<?php
if (!defined('ABSPATH')) exit;

function evive_register_meta() {
    $post_types = get_post_types(['public' => true]);
    foreach ($post_types as $post_type) {
        global $wpdb;
        $meta_keys = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT meta_key FROM $wpdb->postmeta pm
             JOIN $wpdb->posts p ON p.ID = pm.post_id
             WHERE p.post_type = %s
             AND meta_key NOT LIKE '\\_%'
             LIMIT 50",
            $post_type
        ));
        foreach ($meta_keys as $key) {
            register_post_meta($post_type, $key, [
                'show_in_rest' => true,
                'single'       => true,
                'type'         => 'string',
            ]);
        }
    }
}

/**
 * Persist Elementor data directly via $wpdb, bypassing WordPress and Elementor
 * meta hooks (CSS regeneration, filesystem writes) that fail in REST API context.
 */
function evive_save_elementor_data(int $post_id, array $elements): bool {
    global $wpdb;

    $json = wp_json_encode($elements, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }

    $meta_id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_elementor_data' LIMIT 1",
        $post_id
    ));

    if ($meta_id) {
        $wpdb->update(
            $wpdb->postmeta,
            ['meta_value' => $json],
            ['meta_id'    => $meta_id]
        );
    } else {
        $wpdb->insert(
            $wpdb->postmeta,
            ['post_id' => $post_id, 'meta_key' => '_elementor_data', 'meta_value' => $json]
        );
    }

    // Remove stale CSS cache entry so Elementor regenerates on next frontend load.
    $wpdb->delete($wpdb->postmeta, ['post_id' => $post_id, 'meta_key' => '_elementor_css']);

    // Bust WordPress object cache for this post's meta.
    wp_cache_delete($post_id, 'post_meta');

    return true;
}

/**
 * Clear Elementor's per-post CSS/asset cache and notify page-cache layers for a single
 * post. Call after any write to _elementor_data or wp_update_post on Elementor content.
 * All cache actions are no-ops when the respective plugin/function is not active.
 *
 * Consolidated (2026-08-05) from two previously-divergent implementations: this function
 * and Evive_Page_Images::purge_page_caches(), which existed for no good reason -- the
 * widget/content-update paths used this weaker one, missing _elementor_page_assets and an
 * OPcache reset. That's the most likely concrete cause of a banner background-image edit
 * not showing up until a manual Elementor "Clear Files & Data": _elementor_page_assets is
 * a second, separate cache-tracking meta key Elementor uses (alongside _elementor_css) to
 * decide what to regenerate, and leaving it in place could mean a background-image CSS
 * rule doesn't get rebuilt even after _elementor_css is cleared.
 *
 * Deliberately scoped per-post, NOT site-wide: a full wp_cache_flush() / LiteSpeed
 * purge_all() / rocket_clean_domain() (as the old purge_page_caches() also did) forces
 * every page on the site to regenerate its Elementor CSS on its next visitor load -- a
 * real, avoidable performance cost for a site edited multiple times a day, disproportionate
 * to a single widget/image edit. The page-cache-layer notifications below stay per-post
 * (litespeed_purge_post / w3tc_flush_post / rocket_clean_post), which is comparatively cheap
 * and was already the behavior of the weaker version.
 *
 * Theme Builder templates (post_type elementor_library) are the one case where a scoped
 * purge can never be correct: one template can render on many pages (headers, footers,
 * banners applied via Theme Builder conditions), and evive has no reliable way to know
 * which pages a given template affects without reimplementing Elementor's own condition
 * matching. Confirmed (2026-08-07, card #24 reopen) that LiteSpeed Cache ships its own
 * bundled Elementor integration (thirdparty/elementor.cls.php) that listens ONLY for the
 * global do_action('elementor/core/files/clear_cache') -- the same signal Elementor's own
 * "Clear Files & Data" button fires -- and responds with a full litespeed_purge_all(). It
 * does not listen for the per-post litespeed_purge_post fired below, so a template edit's
 * scoped purge was silently never reaching LiteSpeed's actual invalidation logic. Firing
 * that hook here, gated to elementor_library posts only, replicates the manual fix exactly
 * without reopening the performance cost of a site-wide purge on every ordinary edit.
 */
function evive_purge_post_cache(int $post_id): void {
    delete_post_meta($post_id, '_elementor_css');
    delete_post_meta($post_id, '_elementor_page_assets');
    clean_post_cache($post_id);
    do_action('litespeed_purge_post', $post_id);
    do_action('w3tc_flush_post',      $post_id);
    do_action('rocket_clean_post',    $post_id);
    if (get_post_type($post_id) === 'elementor_library') {
        do_action('elementor/core/files/clear_cache');
    }
    if (function_exists('opcache_reset')) opcache_reset();
}

/**
 * Full site-wide cache clear -- extracted from class-update.php's force_clear_cache REST
 * handler (card #24) so card #88's kit-settings write path can call the same real logic
 * instead of duplicating it. Deliberately heavier than evive_purge_post_cache(): calls
 * Plugin::$instance->files_manager->clear_cache() directly (the exact method Elementor's
 * own "Clear Files & Data" button calls) rather than just firing the notification hook that
 * method fires as its own last step -- confirmed via #24 that the hook alone reaches
 * LiteSpeed's listener but does nothing to regenerate Elementor's own physical CSS/asset
 * files, so there is nothing fresh for LiteSpeed to have purged into. Only ever call this
 * for genuinely site-wide changes (kit colors/fonts are rendered via CSS variables present
 * on every page) -- evive_purge_post_cache() stays the default for a single post/page edit.
 */
function evive_purge_all_caches(): array {
    $filesManagerCalled = false;
    if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance) && isset(\Elementor\Plugin::$instance->files_manager)) {
        \Elementor\Plugin::$instance->files_manager->clear_cache();
        $filesManagerCalled = true;
    }
    do_action('litespeed_purge_all', 'evive full cache clear');
    do_action('rocket_clean_domain');
    do_action('w3tc_flush_all');
    if (function_exists('opcache_reset')) opcache_reset();
    return ['elementor_files_manager_clear_cache_called' => $filesManagerCalled];
}

/**
 * Confirm a same-site URL actually resolves (2xx) before it gets written anywhere.
 * Checks via HEAD first, falling back to GET for hosts/CDNs that reject HEAD on media
 * files. URLs on a different host are out of scope (an external provider being slow or
 * down isn't this check's job, and isn't the class of bug this guards against) and are
 * reported as ok/unchecked rather than verified.
 *
 * Shared by any write path that accepts a caller-supplied URL for site media -- currently
 * Evive_Elementor::verify_field_urls() (evive_update_widget) and
 * Evive_Page_Images::repoint_slideshow_image() -- so the HEAD/GET-fallback/same-site-only
 * logic lives in exactly one place instead of being duplicated per call site.
 *
 * @return array{ok: bool, status: int|string|null, checked: bool} checked=false means the
 *         URL was out of scope (not a URL, or a different host) and was never requested.
 */
function evive_verify_same_site_url(string $url): array {
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return ['ok' => true, 'status' => null, 'checked' => false];
    }

    $site_host = wp_parse_url(home_url(), PHP_URL_HOST);
    $host      = wp_parse_url($url, PHP_URL_HOST);
    if (!$host || strcasecmp($host, (string) $site_host) !== 0) {
        return ['ok' => true, 'status' => null, 'checked' => false];
    }

    $resp = wp_remote_head($url, ['timeout' => 4, 'redirection' => 3]);
    $code = is_wp_error($resp) ? null : wp_remote_retrieve_response_code($resp);
    if ($code !== null && $code >= 200 && $code < 300) {
        return ['ok' => true, 'status' => $code, 'checked' => true];
    }

    // HEAD failed or was rejected -- retry once with GET before concluding it's broken.
    $resp2 = wp_remote_get($url, ['timeout' => 4, 'redirection' => 3]);
    $code2 = is_wp_error($resp2) ? null : wp_remote_retrieve_response_code($resp2);
    if ($code2 !== null && $code2 >= 200 && $code2 < 300) {
        return ['ok' => true, 'status' => $code2, 'checked' => true];
    }

    return ['ok' => false, 'status' => $code2 ?? $code ?? 'request_failed', 'checked' => true];
}
