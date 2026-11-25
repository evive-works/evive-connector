<?php
/**
 * REST API, namespaces evive-language/v1 (the old paths, evive's MCP uses them) and evive/v1/language (evive #510):
 *   GET    /search?blog=2&type=page&s=…            posts on another site to link to
 *   GET    /link?post_id=10                        the translations of a post on this site
 *   POST   /link  post_id, target_blog, target_post link two posts (symmetric)
 *   DELETE /link  post_id, target_blog             remove one translation (symmetric)
 * Authentication is the normal WordPress REST auth: cookie + `X-WP-Nonce` (wp_rest) in the editor,
 * or an application password. Every route checks capabilities on each site it touches.
 *
 * @package evive-connector (language, from evive-language 0.1.1; evive #510)
 */

namespace Evive\Connector\Language;

defined( 'ABSPATH' ) || exit;

final class Rest {

	const NS = 'evive-language/v1';

	/** evive #510: the same routes in the Connector's own namespace. */
	const CONNECTOR_NS = 'evive/v1';
	const CONNECTOR_BASE = '/language';

	/** Both namespaces (the evive-language plugin is not active). */
	public static function register(): void {
		self::register_routes( self::NS, '' );
		self::register_connector_only();
	}

	/** Only evive/v1/language/* (while the evive-language plugin is still active and owns evive-language/v1). */
	public static function register_connector_only(): void {
		self::register_routes( self::CONNECTOR_NS, self::CONNECTOR_BASE );
	}

	private static function register_routes( string $ns, string $base ): void {
		$int = [ 'type' => 'integer', 'required' => true, 'minimum' => 1 ];

		register_rest_route( $ns, $base . '/search', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'search' ],
			'permission_callback' => static fn( \WP_REST_Request $r ) => self::may_reach_blog( (int) $r['blog'], 'edit_posts' ), // evive #510
			'args'                => [
				'blog' => $int,
				'type' => [ 'type' => 'string', 'default' => 'page', 'enum' => Plugin::post_types() ],
				's'    => [ 'type' => 'string', 'default' => '' ],
			],
		] );

		register_rest_route( $ns, $base . '/link', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'get_links' ],
				'permission_callback' => static fn( \WP_REST_Request $r ) => current_user_can( 'edit_post', (int) $r['post_id'] ),
				'args'                => [ 'post_id' => $int ],
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'create_link' ],
				// evive #510: was edit_post on the target post itself, which a per-site evive user never has (403).
				'permission_callback' => static fn( \WP_REST_Request $r ) => current_user_can( 'edit_post', (int) $r['post_id'] )
					? self::may_reach_blog( (int) $r['target_blog'], 'edit_post', (int) $r['target_post'] )
					: false,
				'args'                => [ 'post_id' => $int, 'target_blog' => $int, 'target_post' => $int ],
			],
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ __CLASS__, 'delete_link' ],
				'permission_callback' => static fn( \WP_REST_Request $r ) => current_user_can( 'edit_post', (int) $r['post_id'] ),
				'args'                => [ 'post_id' => $int, 'target_blog' => $int ],
			],
		] );
	}

	/**
	 * evive #510: may the current user search or link posts on another site of the network?
	 *  - that site takes part in language switching (Settings → Language on that site; its own administrator decides), and
	 *  - the user can edit there (as in evive-language 0.1.1), or is an administrator of THIS site.
	 * Why the second: on a language network evive has one login per site, an administrator of that site only (not a
	 * member of the other sites, so every check on the other site failed with 403). Linking writes nothing on the other
	 * site but the translation map (post meta `_evive_translations`) of the target post, and of a post there that loses
	 * its place in the group; the target must exist, be of the same post type and not be in the trash (create_link).
	 * No content, status, slug or other meta of the other site changes, and an unlink undoes it. Search then leaves out
	 * private posts (see search()). The site's own administrator could already change every link on this site.
	 *
	 * @return true|\WP_Error
	 */
	private static function may_reach_blog( int $blog_id, string $cap, ?int $object_id = null ) {
		if ( ! Sites::is_participating( $blog_id ) ) {
			return new \WP_Error( 'evive_language_not_participating', __( 'That site is not one of the languages of this network.', 'evive-language' ), [ 'status' => 403 ] );
		}
		if ( self::can_on_blog( $blog_id, $cap, $object_id ) || current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new \WP_Error( 'evive_language_forbidden', __( 'Linking to another language site needs edit rights on that site, or an administrator of this site.', 'evive-language' ), [ 'status' => 403 ] );
	}

	/** Capability check on another site of the network (only sites that take part). */
	private static function can_on_blog( int $blog_id, string $cap, ?int $object_id = null ): bool {
		if ( ! Sites::is_participating( $blog_id ) ) {
			return false;
		}
		return (bool) Sites::on_blog( $blog_id, static function () use ( $cap, $object_id ) {
			return null === $object_id ? current_user_can( $cap ) : current_user_can( $cap, $object_id );
		} );
	}

	public static function search( \WP_REST_Request $r ) {
		$type = (string) $r['type'];
		$s    = (string) $r['s'];
		// evive #510: a user without edit rights on that site (an administrator of this site only) doesn't see its private posts.
		$statuses = [ 'publish', 'draft', 'pending', 'future' ];
		if ( self::can_on_blog( (int) $r['blog'], 'edit_posts' ) ) {
			$statuses[] = 'private';
		}
		return Sites::on_blog( (int) $r['blog'], static function () use ( $type, $s, $statuses ) {
			$query = new \WP_Query( [
				'post_type'      => $type,
				'post_status'    => $statuses,
				's'              => $s,
				'posts_per_page' => 20,
				'orderby'        => '' === $s ? 'title' : 'relevance',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			] );
			return array_map( [ __CLASS__, 'describe' ], $query->posts );
		} );
	}

	public static function get_links( \WP_REST_Request $r ) {
		$post = get_post( (int) $r['post_id'] );
		if ( ! $post || ! in_array( $post->post_type, Plugin::post_types(), true ) ) {
			return new \WP_Error( 'evive_language_invalid_post', __( 'This post type cannot be translated.', 'evive-language' ), [ 'status' => 400 ] );
		}
		return self::links_response( $post->ID );
	}

	public static function create_link( \WP_REST_Request $r ) {
		$here        = get_current_blog_id();
		$post        = get_post( (int) $r['post_id'] );
		$target_blog = (int) $r['target_blog'];
		$target_post = (int) $r['target_post'];
		if ( ! $post || ! in_array( $post->post_type, Plugin::post_types(), true ) ) {
			return new \WP_Error( 'evive_language_invalid_post', __( 'This post type cannot be translated.', 'evive-language' ), [ 'status' => 400 ] );
		}
		if ( $target_blog === $here ) {
			return new \WP_Error( 'evive_language_same_site', __( 'Choose a post on another site.', 'evive-language' ), [ 'status' => 400 ] );
		}
		$target_type = Sites::on_blog( $target_blog, static function () use ( $target_post ) {
			$target = get_post( $target_post );
			return ( $target && 'trash' !== $target->post_status ) ? $target->post_type : null;
		} );
		// evive #510: as in search(), a private post there only for a user who can edit it.
		$target_private = 'private' === Sites::on_blog( $target_blog, static fn() => get_post_status( $target_post ) );
		if ( $target_private && ! self::can_on_blog( $target_blog, 'edit_post', $target_post ) ) {
			$target_type = null;
		}
		if ( $target_type !== $post->post_type ) {
			return new \WP_Error( 'evive_language_type_mismatch', __( 'The translation must exist and be of the same type.', 'evive-language' ), [ 'status' => 400 ] );
		}
		Plugin::linker()->link( $here, $post->ID, $target_blog, $target_post );
		return self::links_response( $post->ID );
	}

	public static function delete_link( \WP_REST_Request $r ) {
		$post = get_post( (int) $r['post_id'] );
		if ( ! $post ) {
			return new \WP_Error( 'evive_language_invalid_post', __( 'This post type cannot be translated.', 'evive-language' ), [ 'status' => 400 ] );
		}
		Plugin::linker()->unlink( get_current_blog_id(), $post->ID, (int) $r['target_blog'] );
		return self::links_response( $post->ID );
	}

	/** { post_id, translations: [ { blog, code, label, post: {…}|null } ] } for every other site. */
	private static function links_response( int $post_id ): array {
		$here  = get_current_blog_id();
		$group = Plugin::linker()->group( $here, $post_id );
		$out   = [];
		foreach ( Sites::all() as $blog_id => $site ) {
			if ( $blog_id === $here ) {
				continue;
			}
			$target = null;
			if ( isset( $group[ $blog_id ] ) ) {
				$target_id = $group[ $blog_id ];
				$target    = Sites::on_blog( $blog_id, static function () use ( $target_id ) {
					$p = get_post( $target_id );
					return $p ? self::describe( $p ) : null;
				} );
			}
			$out[] = [ 'blog' => $blog_id, 'code' => $site['code'], 'label' => $site['label'], 'post' => $target ];
		}
		return [ 'post_id' => $post_id, 'translations' => $out ];
	}

	/** Must run in the context of the post's own site. */
	public static function describe( \WP_Post $p ): array {
		return [
			'id'        => $p->ID,
			'title'     => html_entity_decode( get_the_title( $p ) ?: __( '(no title)', 'evive-language' ), ENT_QUOTES ),
			'status'    => $p->post_status,
			'link'      => get_permalink( $p ),
			'edit_link' => current_user_can( 'edit_post', $p->ID ) ? get_edit_post_link( $p->ID, 'raw' ) : null,
		];
	}
}
