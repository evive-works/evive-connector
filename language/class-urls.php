<?php
/**
 * URL resolution for hreflang and the language switcher. Pure functions: callers pass in sites,
 * URLs and flags; nothing here touches WordPress.
 *
 * A "site" is an array: [ 'blog_id' => int, 'code' => 'nl', 'label' => 'Nederlands',
 *                         'hreflang' => 'nl-NL', 'home' => 'https://example.com/' ].
 *
 * @package evive-connector (language, from evive-language 0.1.1; evive #510)
 */

namespace Evive\Connector\Language;

defined( 'ABSPATH' ) || exit;

final class Urls {

	/** 'nl_NL' → 'nl-NL', 'de_DE_formal' → 'de-DE', 'en' → 'en'. */
	public static function locale_to_hreflang( string $locale ): string {
		$parts = explode( '_', $locale );
		if ( '' === $parts[0] ) {
			return 'en';
		}
		$tag = strtolower( $parts[0] );
		if ( isset( $parts[1] ) && preg_match( '/^[A-Za-z]{2}$|^\d{3}$/', $parts[1] ) ) {
			$tag .= '-' . strtoupper( $parts[1] );
		}
		return $tag;
	}

	/** 'nl_NL' → 'nl'. */
	public static function locale_to_code( string $locale ): string {
		$code = strtolower( explode( '_', $locale )[0] );
		return '' === $code ? 'en' : $code;
	}

	/**
	 * hreflang entries for one page.
	 *
	 * @param array<int,array> $sites      Participating sites, keyed by blog ID, in display order.
	 * @param array<int,string> $urls      Indexable URL of this page per blog ID (the current page included).
	 * @param int|null          $x_default Blog ID of the international fallback site, or null.
	 * @return array<int,array{hreflang:string,href:string}> Empty when the page has no translation.
	 */
	public static function hreflang_entries( array $sites, array $urls, ?int $x_default ): array {
		$entries = [];
		foreach ( $sites as $blog_id => $site ) {
			if ( ! empty( $urls[ $blog_id ] ) ) {
				$entries[] = [ 'hreflang' => $site['hreflang'], 'href' => $urls[ $blog_id ] ];
			}
		}
		if ( count( $entries ) < 2 ) {
			return [];
		}
		if ( null !== $x_default && ! empty( $urls[ $x_default ] ) ) {
			$entries[] = [ 'hreflang' => 'x-default', 'href' => $urls[ $x_default ] ];
		}
		return $entries;
	}

	/**
	 * The `<link rel="alternate">` tags for a set of entries.
	 *
	 * @param array<int,array{hreflang:string,href:string}> $entries From hreflang_entries().
	 */
	public static function hreflang_tags( array $entries ): string {
		$html = '';
		foreach ( $entries as $entry ) {
			$html .= sprintf(
				'<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
				htmlspecialchars( $entry['hreflang'], ENT_QUOTES, 'UTF-8' ),
				htmlspecialchars( $entry['href'], ENT_QUOTES, 'UTF-8' )
			);
		}
		return $html;
	}

	/**
	 * Language switcher items: one per site. Target = the linked translation if there is one,
	 * otherwise that site's front page. The current site is marked.
	 *
	 * @param array<int,array> $sites      Participating sites, keyed by blog ID, in display order.
	 * @param int              $current    Current blog ID.
	 * @param array<int,string> $translated URL of the linked translation per blog ID (may be incomplete).
	 * @param string            $current_url URL of the page being viewed.
	 * @return array<int,array{blog_id:int,code:string,label:string,hreflang:string,url:string,current:bool}>
	 */
	public static function switcher_items( array $sites, int $current, array $translated, string $current_url ): array {
		$items = [];
		foreach ( $sites as $blog_id => $site ) {
			$is_current = ( $blog_id === $current );
			$items[]    = [
				'blog_id'  => $blog_id,
				'code'     => $site['code'],
				'label'    => $site['label'],
				'hreflang' => $site['hreflang'],
				'url'      => $is_current ? $current_url : ( $translated[ $blog_id ] ?? $site['home'] ),
				'current'  => $is_current,
			];
		}
		return $items;
	}
}
