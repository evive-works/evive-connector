<?php
/**
 * Translation maps: { blog_id: post_id, ... } stored as JSON in post meta `_evive_translations`.
 * Pure functions, no WordPress calls.
 *
 * @package evive-connector (language, from evive-language 0.1.1; evive #510)
 */

namespace Evive\Connector\Language;

defined( 'ABSPATH' ) || exit;

final class Translations {

	const META_KEY = '_evive_translations';

	/**
	 * Turn stored meta (JSON string or array) into a clean int => int map, sorted by blog ID.
	 *
	 * @param mixed $raw Meta value.
	 * @return array<int,int>
	 */
	public static function normalize( $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$map = [];
		foreach ( $raw as $blog => $post ) {
			if ( is_numeric( $blog ) && is_numeric( $post ) && (int) $blog > 0 && (int) $post > 0 ) {
				$map[ (int) $blog ] = (int) $post;
			}
		}
		ksort( $map );
		return $map;
	}

	/**
	 * JSON for storage: {"1":10,"2":20}.
	 *
	 * @param array<int,int> $map Translation map.
	 */
	public static function encode( array $map ): string {
		$map = self::normalize( $map );
		$out = [];
		foreach ( $map as $blog => $post ) {
			$out[ (string) $blog ] = $post;
		}
		return (string) json_encode( (object) $out );
	}
}
