<?php
/**
 * WP-CLI: `wp evive-language audit|link|unlink`.
 *
 * @package evive-connector (language, from evive-language 0.1.1; evive #510)
 */

namespace Evive\Connector\Language;

defined( 'ABSPATH' ) || exit;

/**
 * Translation links between the sites of the network.
 */
final class Cli {

	/**
	 * Lists published posts that are not linked to a translation on every other site.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<type>]
	 * : Post type to check.
	 * ---
	 * default: page
	 * ---
	 *
	 * [--format=<format>]
	 * : table, csv, json, yaml or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp evive-language audit
	 *     wp evive-language audit --post_type=post --format=csv
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function audit( $args, $assoc_args ): void {
		$type  = $assoc_args['post_type'] ?? 'page';
		$sites = Sites::all();
		$rows  = [];
		foreach ( $sites as $blog_id => $site ) {
			$found = Sites::on_blog( $blog_id, static function () use ( $type ) {
				return get_posts( [ 'post_type' => $type, 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC' ] );
			} );
			foreach ( $found as $post ) {
				$group   = Plugin::linker()->group( $blog_id, $post->ID );
				$missing = array_diff_key( $sites, $group );
				if ( $missing ) {
					$rows[] = [
						'site'    => $site['code'],
						'blog_id' => $blog_id,
						'post_id' => $post->ID,
						'title'   => $post->post_title,
						'missing' => implode( ',', array_column( $missing, 'code' ) ),
					];
				}
			}
		}
		$format = $assoc_args['format'] ?? 'table';
		if ( 'count' === $format ) {
			\WP_CLI::line( (string) count( $rows ) );
			return;
		}
		if ( ! $rows ) {
			\WP_CLI::success( sprintf( 'Every published %s is linked on all %d sites.', $type, count( $sites ) ) );
			return;
		}
		\WP_CLI\Utils\format_items( $format, $rows, [ 'site', 'blog_id', 'post_id', 'title', 'missing' ] );
	}

	/**
	 * Links a post on the current site to a post on another site (symmetric).
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : Post on the current site (use --url to pick the site).
	 *
	 * <target_blog>
	 * : Blog ID of the other site.
	 *
	 * <target_post>
	 * : Post ID on the other site.
	 *
	 * ## EXAMPLES
	 *
	 *     wp evive-language link 12 2 7
	 *     wp --url=example.com/en/ evive-language link 7 1 12
	 *
	 * @param array $args Positional arguments.
	 */
	public function link( $args ): void {
		[ $post_id, $target_blog, $target_post ] = array_map( 'intval', $args );
		$group = Plugin::linker()->link( get_current_blog_id(), $post_id, $target_blog, $target_post );
		\WP_CLI::success( 'Linked: ' . Translations::encode( $group ) );
	}

	/**
	 * Removes the translation on another site from a post's group (symmetric).
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : Post on the current site.
	 *
	 * <target_blog>
	 * : Blog ID of the translation to remove.
	 *
	 * @param array $args Positional arguments.
	 */
	public function unlink( $args ): void {
		[ $post_id, $target_blog ] = array_map( 'intval', $args );
		$group = Plugin::linker()->unlink( get_current_blog_id(), $post_id, $target_blog );
		\WP_CLI::success( 'Remaining group: ' . Translations::encode( $group ) );
	}
}
