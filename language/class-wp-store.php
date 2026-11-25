<?php
/**
 * Store implementation on post meta, across sites of the network.
 *
 * @package evive-connector (language, from evive-language 0.1.1; evive #510)
 */

namespace Evive\Connector\Language;

defined( 'ABSPATH' ) || exit;

final class WP_Store implements Store {

	public function get( int $blog_id, int $post_id ): array {
		return Sites::on_blog( $blog_id, static function () use ( $post_id ) {
			return Translations::normalize( get_post_meta( $post_id, Translations::META_KEY, true ) );
		} );
	}

	public function set( int $blog_id, int $post_id, array $map ): void {
		Sites::on_blog( $blog_id, static function () use ( $post_id, $map ) {
			update_post_meta( $post_id, Translations::META_KEY, wp_slash( Translations::encode( $map ) ) );
		} );
	}

	public function delete( int $blog_id, int $post_id ): void {
		Sites::on_blog( $blog_id, static function () use ( $post_id ) {
			delete_post_meta( $post_id, Translations::META_KEY );
		} );
	}
}
