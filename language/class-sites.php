<?php
/**
 * The sites (languages) of the network and their settings.
 *
 * @package evive-connector (language, from evive-language 0.1.1; evive #510)
 */

namespace Evive\Connector\Language;

defined( 'ABSPATH' ) || exit;

final class Sites {

	/** Per-site option: [ 'enabled' => bool, 'code' => string, 'label' => string, 'hreflang' => string ]. */
	const OPTION = 'evive_language';

	/** Network option: blog ID of the x-default (international fallback) site, 0 = none. */
	const X_DEFAULT_OPTION = 'evive_language_x_default';

	/** Native language names for default labels; anything else falls back to the language code. */
	const NATIVE_NAMES = [
		'da' => 'Dansk',
		'de' => 'Deutsch',
		'en' => 'English',
		'es' => 'Español',
		'fi' => 'Suomi',
		'fr' => 'Français',
		'it' => 'Italiano',
		'nb' => 'Norsk',
		'nl' => 'Nederlands',
		'pl' => 'Polski',
		'pt' => 'Português',
		'sv' => 'Svenska',
	];

	/** @var array<int,array>|null */
	private static $cache = null;

	/**
	 * All participating sites, keyed by blog ID, in blog ID order.
	 *
	 * @return array<int,array{blog_id:int,code:string,label:string,hreflang:string,home:string,locale:string}>
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$ids = is_multisite()
			? get_sites( [ 'fields' => 'ids', 'archived' => 0, 'deleted' => 0, 'spam' => 0, 'number' => 100, 'orderby' => 'id' ] )
			: [ get_current_blog_id() ];

		$sites = [];
		foreach ( $ids as $blog_id ) {
			$blog_id  = (int) $blog_id;
			$settings = self::settings( $blog_id );
			if ( $settings['enabled'] ) {
				$sites[ $blog_id ] = $settings + [ 'blog_id' => $blog_id ];
			}
		}
		/**
		 * Filters the sites that take part in language switching and hreflang.
		 *
		 * @param array<int,array> $sites Keyed by blog ID.
		 */
		self::$cache = (array) apply_filters( 'evive_language_sites', $sites );
		return self::$cache;
	}

	public static function flush(): void {
		self::$cache = null;
	}

	public static function is_participating( int $blog_id ): bool {
		return isset( self::all()[ $blog_id ] );
	}

	/** Settings of one site, with defaults derived from its WPLANG. */
	public static function settings( int $blog_id ): array {
		[ $stored, $locale, $home ] = self::on_blog( $blog_id, static function () {
			return [ (array) get_option( self::OPTION, [] ), (string) get_option( 'WPLANG' ) ?: 'en_US', home_url( '/' ) ];
		} );
		$defaults = self::defaults( $locale );
		$settings = [
			'enabled'  => array_key_exists( 'enabled', $stored ) ? (bool) $stored['enabled'] : true,
			'code'     => ! empty( $stored['code'] ) ? (string) $stored['code'] : $defaults['code'],
			'label'    => ! empty( $stored['label'] ) ? (string) $stored['label'] : $defaults['label'],
			'hreflang' => ! empty( $stored['hreflang'] ) ? (string) $stored['hreflang'] : $defaults['hreflang'],
			'locale'   => $locale,
			'home'     => $home,
		];
		return $settings;
	}

	/** @return array{code:string,label:string,hreflang:string} */
	public static function defaults( string $locale ): array {
		$code = Urls::locale_to_code( $locale );
		return [
			'code'     => $code,
			'label'    => self::NATIVE_NAMES[ $code ] ?? strtoupper( $code ),
			'hreflang' => Urls::locale_to_hreflang( $locale ),
		];
	}

	/** Blog ID of the x-default site, or null if unset or not participating. */
	public static function x_default(): ?int {
		$id = (int) get_site_option( self::X_DEFAULT_OPTION, 0 );
		return ( $id && self::is_participating( $id ) ) ? $id : null;
	}

	/**
	 * Run a callback in the context of another site and return its result. Rewrite rules are
	 * re-initialised so permalinks come out in the other site's structure.
	 *
	 * @template T
	 * @param callable():T $callback
	 * @return T
	 */
	public static function on_blog( int $blog_id, callable $callback ) {
		if ( ! is_multisite() || get_current_blog_id() === $blog_id ) {
			return $callback();
		}
		global $wp_rewrite;
		switch_to_blog( $blog_id );
		$wp_rewrite->init();
		try {
			return $callback();
		} finally {
			restore_current_blog();
			$wp_rewrite->init();
		}
	}
}
