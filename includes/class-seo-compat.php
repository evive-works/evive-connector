<?php
if (!defined('ABSPATH')) exit;

/**
 * Shared Yoast-required / competing-schema-plugin detection for evive's GEO/schema features
 * (card #189). Each constant below was confirmed against the real, current WordPress.org plugin
 * source (not guessed from plugin slugs) -- downloaded and inspected to verify it is genuinely
 * defined, unconditionally, the moment that plugin loads: RANK_MATH_VERSION (rank-math.php,
 * defined in setup() before any requirements gate), AIOSEO_VERSION (app/AIOSEO.php, defined in
 * init()'s constants() step before the uninstalling gate), SEOPRESS_VERSION / SLIM_SEO_VER /
 * THE_SEO_FRAMEWORK_VERSION (all top-level defines in each plugin's own main file).
 */
class Evive_Seo_Compat {

    const COMPETING_PLUGINS = [
        'Rank Math'          => 'RANK_MATH_VERSION',
        'All in One SEO'     => 'AIOSEO_VERSION',
        'SEOPress'           => 'SEOPRESS_VERSION',
        'Slim SEO'           => 'SLIM_SEO_VER',
        'The SEO Framework'  => 'THE_SEO_FRAMEWORK_VERSION',
    ];

    public static function yoast_active() {
        return defined('WPSEO_VERSION');
    }

    public static function active_competing_plugins() {
        $active = [];
        foreach (self::COMPETING_PLUGINS as $name => $constant) {
            if (defined($constant)) $active[] = $name;
        }
        return $active;
    }

    /**
     * Real gate for any evive-authored schema piece (FAQPage now, Review/LocalBusiness later).
     * Yoast-absent is a clean no-op -- there is nothing to hook into. Yoast-alongside-a-competitor
     * is the genuinely dangerous state (a real, live duplicate-schema risk) -- this deliberately
     * blocks rather than guessing which plugin "wins", the same discipline #188's investigation
     * used to avoid writing a second, competing <script> block in the first place.
     */
    public static function schema_status() {
        $competing = self::active_competing_plugins();

        if (!self::yoast_active()) {
            return [
                'ready'              => false,
                'reason'             => 'yoast_missing',
                'message'            => 'Yoast SEO is required for structured data (FAQ, Review, etc.) and is not currently active on this site.',
                'competing_plugins'  => $competing,
            ];
        }

        if (!empty($competing)) {
            return [
                'ready'              => false,
                'reason'             => 'competing_plugin_active',
                'message'            => 'Yoast SEO is active, but so is ' . implode(', ', $competing) . '. Structured data is disabled until only one SEO plugin is active, to avoid conflicting schema markup.',
                'competing_plugins'  => $competing,
            ];
        }

        return ['ready' => true, 'reason' => null, 'message' => null, 'competing_plugins' => []];
    }
}
