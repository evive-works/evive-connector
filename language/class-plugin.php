<?php
/**
 * Wiring: hooks, the current request's translations, hreflang output, the switcher block.
 * evive #510: from evive-language 0.1.1 into the Connector. Same data (post meta `_evive_translations`, options
 * `evive_language` / `evive_language_x_default`), same block name, same REST paths (evive-language/v1, plus
 * evive/v1/language). When the evive-language plugin itself is still active, it keeps all of this and the Connector
 * only shows a notice (see language.php).
 *
 * @package evive-connector (language, from evive-language 0.1.1; evive #510)
 */

namespace Evive\Connector\Language;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** @var Linker|null */
	private static $linker = null;

	/** Folder of the language feature inside the Connector (evive #510). */
	public static function dir(): string {
		return EVIVE_PATH . 'language/';
	}

	/** The Connector's main file, for plugins_url() and plugin_basename(). */
	public static function plugin_file(): string {
		return EVIVE_PATH . 'evive-connector.php';
	}

	public static function boot(): void {
		add_action( 'init', [ __CLASS__, 'init' ] );
		add_action( 'wp_head', [ __CLASS__, 'print_hreflang' ], 1 );
		add_action( 'rest_api_init', [ Rest::class, 'register' ] );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'enqueue_editor' ] );
		add_action( 'update_option_' . Sites::OPTION, [ Sites::class, 'flush' ] );
		add_action( 'before_delete_post', [ __CLASS__, 'forget_deleted_post' ] );
		// evive #510: the settings screens only mean something on a Multisite network (one site per language).
		if ( is_multisite() ) {
			Settings::boot();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'evive-language', Cli::class );
		}
	}

	public static function init(): void {
		load_plugin_textdomain( 'evive-language', false, dirname( plugin_basename( self::plugin_file() ) ) . '/language/languages' );
		// evive #510: block.json "render" needs WordPress 6.1; the Connector still allows 6.0.
		if ( version_compare( get_bloginfo( 'version' ), '6.1', '<' ) ) {
			return;
		}
		$block = register_block_type( self::dir() . 'blocks/switcher' );
		if ( $block && ! empty( $block->editor_script_handles ) ) {
			foreach ( $block->editor_script_handles as $handle ) {
				wp_set_script_translations( $handle, 'evive-language', self::dir() . 'languages' );
			}
		}
	}

	public static function linker(): Linker {
		return self::$linker ?? ( self::$linker = new Linker( new WP_Store() ) );
	}

	/** @return string[] Post types that can be linked. */
	public static function post_types(): array {
		/**
		 * Filters the post types that can have translations.
		 *
		 * @param string[] $types Default: page, post.
		 */
		return (array) apply_filters( 'evive_language_post_types', [ 'page', 'post' ] );
	}

	/**
	 * Is a post on the current blog a valid hreflang target: published, and not set to noindex?
	 */
	public static function is_indexable( \WP_Post $post ): bool {
		$indexable = 'publish' === $post->post_status
			&& '1' !== (string) get_post_meta( $post->ID, '_yoast_wpseo_meta-robots-noindex', true );
		/**
		 * Filters whether a post may appear as an hreflang target.
		 *
		 * @param bool     $indexable
		 * @param \WP_Post $post
		 */
		return (bool) apply_filters( 'evive_language_is_indexable', $indexable, $post );
	}

	/**
	 * URLs of the current page per blog ID: the front pages on the front page, the linked translations
	 * on a singular view, nothing elsewhere.
	 *
	 * @param bool $indexable_only Only published, non-noindex targets (hreflang); false = published (switcher).
	 * @return array<int,string>
	 */
	public static function current_urls( bool $indexable_only ): array {
		$sites = Sites::all();
		$here  = get_current_blog_id();
		if ( ! isset( $sites[ $here ] ) ) {
			return [];
		}
		if ( is_front_page() ) {
			// Front pages of all sites are always each other's translation.
			return array_map( static fn( $site ) => $site['home'], $sites );
		}
		if ( ! is_singular( self::post_types() ) ) {
			return [];
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return [];
		}
		$urls = [];
		foreach ( self::linker()->group( $here, $post->ID ) as $blog_id => $post_id ) {
			if ( ! isset( $sites[ $blog_id ] ) ) {
				continue;
			}
			$url = Sites::on_blog( $blog_id, static function () use ( $post_id, $indexable_only ) {
				$target = get_post( $post_id );
				if ( ! $target || 'publish' !== $target->post_status ) {
					return '';
				}
				return ( ! $indexable_only || self::is_indexable( $target ) ) ? (string) get_permalink( $target ) : '';
			} );
			if ( $url ) {
				$urls[ $blog_id ] = $url;
			} elseif ( $blog_id === $here ) {
				return []; // The page itself isn't indexable: no hreflang at all.
			}
		}
		return $urls;
	}

	/** A permanently deleted post leaves its translation group; trashed posts keep their links. */
	public static function forget_deleted_post( int $post_id ): void {
		$post = get_post( $post_id );
		if ( $post && in_array( $post->post_type, self::post_types(), true ) ) {
			self::linker()->remove( get_current_blog_id(), $post_id );
		}
	}

	public static function print_hreflang(): void {
		$entries = Urls::hreflang_entries( Sites::all(), self::current_urls( true ), Sites::x_default() );
		echo Urls::hreflang_tags( $entries ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in hreflang_tags().
	}

	/** Items for the switcher block on the current request. */
	public static function switcher_items(): array {
		$here = get_current_blog_id();
		$urls = self::current_urls( false );
		$self = $urls[ $here ] ?? esc_url_raw( set_url_scheme( 'http://' . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) . sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ) ) );
		return Urls::switcher_items( Sites::all(), $here, $urls, $self );
	}

	public static function enqueue_editor(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->post_type, self::post_types(), true ) || count( Sites::all() ) < 2 ) {
			return;
		}
		$asset_file = self::dir() . 'build/panel.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		wp_enqueue_script( 'evive-language-panel', plugins_url( 'language/build/panel.js', self::plugin_file() ), $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( 'evive-language-panel', 'evive-language', self::dir() . 'languages' );

		$sites = [];
		foreach ( Sites::all() as $blog_id => $site ) {
			$sites[] = [ 'blog' => $blog_id, 'code' => $site['code'], 'label' => $site['label'] ];
		}
		wp_add_inline_script(
			'evive-language-panel',
			'window.eviveLanguage = ' . wp_json_encode( [ 'currentBlog' => get_current_blog_id(), 'sites' => $sites, 'postTypes' => self::post_types() ] ) . ';',
			'before'
		);
	}
}
