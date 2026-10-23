<?php
if (!defined('ABSPATH')) exit;

class Evive_Site_Profile {

    // Post types that are builder internals, not real content
    private $excluded_types = [
        'attachment', 'header', 'footer', 'elementor_library',
        'e-floating-buttons', 'opal_gallery',
    ];

    public function register_routes() {
        register_rest_route('evive/v1', '/site-profile', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_site_profile'],
            'permission_callback' => function () { return current_user_can('edit_posts'); },
        ]);
    }

    public function get_site_profile() {
        global $wpdb;

        // ── Plugins ──────────────────────────────────────────────────────────
        $sr_table      = $wpdb->prefix . 'revslider_sliders';
        $sr_tbl_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $sr_table)) === $sr_table;
        $sliders_in_use = $sr_tbl_exists ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$sr_table}") : 0;
        $sr_active     = class_exists('RevSlider') || class_exists('RevSliderFront') || class_exists('UniteFunctionsRev');
        $sr_version    = (defined('RS_REVISION') ? RS_REVISION : get_option('revslider-version')) ?: null;
        $sr_entry      = [
            'active'         => $sr_active,
            'version'        => $sr_version,
            'sliders_in_use' => $sliders_in_use,
        ];
        if ($sr_active && $sliders_in_use === 0) {
            $sr_entry['note'] = 'Slider Revolution installed but no sliders found on this site.';
        }

        // Site Kit: active/connected are cheap checks; module settings are only read once
        // connected, since an unconnected install has nothing meaningful in these options.
        $sk_active    = defined('GOOGLESITEKIT_VERSION');
        $sk_connected = $sk_active && (bool) get_option('googlesitekit_has_connected_admins');
        $sk_modules   = [];
        if ($sk_connected) {
            $sk_sc = get_option('googlesitekit_search-console_settings');
            if (!empty($sk_sc['propertyID'])) $sk_modules['search_console'] = $sk_sc['propertyID'];
            // Analytics/Ads field names follow Site Kit's documented settings schema but are
            // unverified against a real connected site (neither module is configured on the
            // site this was built against) -- confirm field names once a live example exists.
            $sk_ga4 = get_option('googlesitekit_analytics-4_settings');
            if (!empty($sk_ga4['propertyID'])) $sk_modules['analytics_ga4'] = $sk_ga4['propertyID'];
            $sk_ads = get_option('googlesitekit_ads_settings');
            if (!empty($sk_ads['conversionID'])) $sk_modules['ads'] = $sk_ads['conversionID'];
        }
        $sk_entry = [
            'active'    => $sk_active,
            'version'   => $sk_active ? GOOGLESITEKIT_VERSION : null,
            'connected' => $sk_connected,
            'modules'   => $sk_modules,
        ];

        // CookieYes: active banner = the enabled banner marked as default, falling back to
        // any other enabled banner if no default is set.
        $cky_active    = defined('CLI_VERSION');
        $cky_connected = false;
        $cky_banner    = null;
        if ($cky_active) {
            $cky_settings  = get_option('cky_settings');
            $cky_connected = !empty($cky_settings['account']['connected']);
            $cky_table     = $wpdb->prefix . 'cky_banners';
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $cky_table)) === $cky_table) {
                $row = $wpdb->get_row("SELECT name FROM {$cky_table} WHERE status = 1 AND banner_default = 1 LIMIT 1", ARRAY_A)
                    ?: $wpdb->get_row("SELECT name FROM {$cky_table} WHERE status = 1 LIMIT 1", ARRAY_A);
                $cky_banner = $row ? $row['name'] : null;
            }
        }
        $cky_entry = [
            'active'        => $cky_active,
            'version'       => $cky_active ? CLI_VERSION : null,
            'connected'     => $cky_connected,
            'active_banner' => $cky_banner,
        ];

        $plugins = [
            'elementor'         => ['active' => defined('ELEMENTOR_VERSION'),                                         'version' => defined('ELEMENTOR_VERSION')   ? ELEMENTOR_VERSION   : null],
            'yoast'             => ['active' => defined('WPSEO_VERSION') || class_exists('WPSEO_Options'),            'version' => defined('WPSEO_VERSION')       ? WPSEO_VERSION       : null],
            'polylang'          => ['active' => function_exists('pll_the_languages') || class_exists('Polylang'),     'version' => defined('POLYLANG_VERSION')    ? POLYLANG_VERSION    : null],
            'litespeed_cache'   => ['active' => class_exists('LiteSpeed_Cache') || class_exists('LiteSpeed\\Core'),   'version' => defined('LSCWP_V')             ? LSCWP_V             : null],
            'slider_revolution' => $sr_entry,
            'updraftplus'       => ['active' => class_exists('UpdraftPlus') || defined('UPDRAFTPLUS_VERSION'),        'version' => defined('UPDRAFTPLUS_VERSION') ? UPDRAFTPLUS_VERSION : null],
            'woocommerce'       => ['active' => defined('WC_VERSION') || class_exists('WooCommerce'),                 'version' => defined('WC_VERSION')          ? WC_VERSION          : null],
            'site_kit'          => $sk_entry,
            'cookieyes'         => $cky_entry,
        ];

        // ── Post types ───────────────────────────────────────────────────────
        $raw_types      = get_post_types(['public' => true], 'objects');
        $post_types     = [];
        $cpt_items      = [];   // keyed by post type name
        $elementor_maps = [];   // keyed by post type name

        foreach ($raw_types as $pt) {
            // Skip builder internals
            if (in_array($pt->name, $this->excluded_types, true)) continue;

            $counts = wp_count_posts($pt->name);
            $count  = (int) ($counts->publish ?? 0);

            // Check if any published item has Elementor data
            $has_elementor = (bool) $wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM {$wpdb->postmeta} pm
                 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE p.post_type = %s AND p.post_status = 'publish'
                   AND pm.meta_key = '_elementor_data'
                   AND pm.meta_value != '' AND pm.meta_value != '[]'
                 LIMIT 1",
                $pt->name
            ));

            // Non-underscore meta keys, filtered to exclude theme/plugin noise.
            // osf_* layout/config fields are excluded EXCEPT for osf_{post_type}_*
            // content fields (e.g. osf_portfolio_gallery, osf_portfolio_start_date).
            $custom_fields = [];
            if ($count > 0) {
                // Whitelist: only fields whose values are short real content
                // (not serialized, not JSON, not long). Keys with >2 underscores
                // are excluded as they tend to be internal plugin storage.
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT meta_key, MIN(meta_value) AS sample
                     FROM {$wpdb->postmeta} pm
                     JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                     WHERE p.post_type = %s
                       AND meta_key NOT LIKE '\\_%%'
                       AND (LENGTH(meta_key) - LENGTH(REPLACE(meta_key, '_', ''))) <= 2
                       AND meta_value != '' AND meta_value IS NOT NULL
                     GROUP BY meta_key
                     HAVING MAX(LENGTH(meta_value)) < 100
                     ORDER BY meta_key
                     LIMIT 20",
                    $pt->name
                ));
                foreach ($rows ?: [] as $row) {
                    $v = $row->sample;
                    if (is_serialized($v)) continue;
                    $t = ltrim($v);
                    if ($t !== '' && ($t[0] === '{' || $t[0] === '[')) continue;
                    $custom_fields[] = $row->meta_key;
                }
            }

            $entry = [
                'name'          => $pt->name,
                'label'         => $pt->label,
                'rest_base'     => $pt->rest_base ?: $pt->name,
                'has_elementor' => $has_elementor,
                'count'         => $count,
            ];
            if (!empty($custom_fields)) $entry['custom_fields'] = $custom_fields;
            $post_types[] = $entry;

            // Collect all items for pages and custom post types (not 'post')
            if ($pt->name === 'page' || !in_array($pt->name, ['post'], true)) {
                $items = get_posts([
                    'post_type'        => $pt->name,
                    'post_status'      => 'any',
                    'numberposts'      => -1,
                    'orderby'          => $pt->name === 'page' ? 'menu_order' : 'date',
                    'order'            => $pt->name === 'page' ? 'ASC' : 'DESC',
                    'suppress_filters' => false,
                ]);
                $pt_items = [];
                foreach ($items as $p) {
                    $pt_items[] = [
                        'id'     => $p->ID,
                        'title'  => $p->post_title,
                        'slug'   => $p->post_name,
                        'status' => $p->post_status,
                    ];
                }
                $cpt_items[$pt->name] = $pt_items;
            }

            // Elementor widget map: sample from the item with the richest Elementor
            // data (longest meta_value), to capture a full representative layout.
            // Ordering by LENGTH DESC avoids picking cloned items whose _elementor_data
            // is still '[]' but whose post_modified is most recent.
            if ($has_elementor) {
                $elem_id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT p.ID FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                       AND pm.meta_key = '_elementor_data'
                       AND LENGTH(pm.meta_value) > 50
                     WHERE p.post_type = %s AND p.post_status = 'publish'
                     ORDER BY LENGTH(pm.meta_value) DESC, p.post_modified DESC
                     LIMIT 1",
                    $pt->name
                ));
                if ($elem_id) {
                    $raw     = get_post_meta($elem_id, '_elementor_data', true);
                    $widgets = [];
                    if ($raw) {
                        $data = $this->decode_elementor_data($raw);
                        if (is_array($data)) {
                            $this->extract_widgets($data, $widgets);
                        }
                    }
                    if (!empty($widgets)) {
                        $elementor_maps[$pt->name] = [
                            'sample_id' => $elem_id,
                            'widgets'   => $widgets,
                        ];
                    }
                }
            }
        }

        // ── Taxonomies ───────────────────────────────────────────────────────
        $raw_taxes  = get_taxonomies(['public' => true], 'objects');
        $taxonomies = [];
        foreach ($raw_taxes as $tax) {
            $terms = get_terms(['taxonomy' => $tax->name, 'hide_empty' => false]);
            $term_names = [];
            if (!is_wp_error($terms)) {
                $seen = [];
                foreach ($terms as $term) {
                    // Decode HTML entities (e.g. &amp; → &)
                    $name  = html_entity_decode($term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    // Deduplicate case-insensitively (Polylang creates per-language duplicates)
                    $lower = mb_strtolower($name, 'UTF-8');
                    if (!isset($seen[$lower])) {
                        $seen[$lower] = true;
                        $term_names[] = $name;
                    }
                }
            }
            $taxonomies[] = [
                'name'       => $tax->name,
                'label'      => $tax->label,
                'post_types' => array_values($tax->object_type),
                'terms'      => $term_names,
            ];
        }

        // ── Languages ────────────────────────────────────────────────────────
        $polylang_active     = function_exists('pll_the_languages') || function_exists('pll_current_language');
        $primary_language    = 'en';
        $secondary_languages = [];
        if ($polylang_active && function_exists('pll_languages_list') && function_exists('pll_default_language')) {
            $all_langs        = pll_languages_list(['fields' => 'slug']);
            $default_lang     = pll_default_language('slug');
            $primary_language = $default_lang ?: 'en';
            $secondary_languages = array_values(array_filter(
                (array) $all_langs,
                function ($l) use ($primary_language) { return $l !== $primary_language; }
            ));
        } else {
            $locale           = get_locale();
            $primary_language = explode('_', $locale)[0] ?: 'en';
        }

        // ── Recent media ─────────────────────────────────────────────────────
        $media_posts  = get_posts([
            'post_type'   => 'attachment',
            'post_status' => 'any',
            'numberposts' => 20,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);
        $recent_media = [];
        foreach ($media_posts as $m) {
            $filepath = get_attached_file($m->ID);
            $filename = $filepath ? basename($filepath) : $m->post_name;
            if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'zip') continue;
            $recent_media[] = [
                'id'       => $m->ID,
                'filename' => $filename,
                'url'      => wp_get_attachment_url($m->ID) ?: '',
                'alt'      => get_post_meta($m->ID, '_wp_attachment_image_alt', true) ?: '',
            ];
        }


                // ── Assemble result ──────────────────────────────────────────────────
        $result = [
            'plugins'               => $plugins,
            'post_types'            => $post_types,
            'taxonomies'            => $taxonomies,
            'languages'             => [
                'primary_language'    => $primary_language,
                'secondary_languages' => $secondary_languages,
                'polylang_active'     => $polylang_active,
            ],
            'pages'                 => $cpt_items['page'] ?? [],
            'elementor_widget_maps' => $elementor_maps,
            'recent_media'          => $recent_media,
            'generated_at'          => gmdate('c'),
            'site_url'              => get_site_url(),
            'site_name'             => get_bloginfo('name'),
            'wordpress_version'     => get_bloginfo('version'),
            'plugin_version'        => defined('EVIVE_VERSION') ? EVIVE_VERSION : null,
        ];

        // One top-level key per custom post type (exclude 'page', 'post')
        foreach ($cpt_items as $pt_name => $items) {
            if (in_array($pt_name, ['page', 'post'], true)) continue;
            $result[$pt_name] = $items;
        }

        return rest_ensure_response($result);
    }

    /**
     * Decode _elementor_data into a flat array of element objects.
     *
     * Handles several storage variants:
     *  1. Standard JSON array:  [{"elType":"section",...}, ...]
     *  2. Double-encoded string: "\"[{...}]\""
     *  3. Document-wrapper object: {"elements":[...], "settings":{}}
     *  4. Already-decoded PHP array (from get_post_meta on PHP-serialised meta)
     */
    private function decode_elementor_data($raw) {
        // Already a PHP array (PHP-serialised meta decoded by get_post_meta)
        if (is_array($raw)) {
            $data = $raw;
        } else {
            $data = json_decode($raw, true);
            // Double-encoded: value was itself a JSON string
            if (is_string($data)) {
                $data = json_decode($data, true);
            }
        }

        if (!is_array($data)) return null;

        // Document-wrapper object: {"type":"...","version":"...","elements":[...]}
        // Detected when the array has a string 'elements' key at the root
        if (isset($data['elements']) && is_array($data['elements'])) {
            return $data['elements'];
        }

        return $data;
    }

    /**
     * Recursively collect all widget elements from an Elementor elements array.
     * Handles sections, columns, containers, and nested containers at any depth.
     */
    private function extract_widgets(array $elements, array &$widgets) {
        foreach ($elements as $el) {
            if (!is_array($el)) continue;
            $el_type = $el['elType'] ?? '';
            if ($el_type === 'widget') {
                $widgets[] = [
                    'id'   => $el['id'] ?? '',
                    'type' => $el['widgetType'] ?? '',
                ];
            }
            // Recurse into child elements (sections → columns → widgets,
            // or containers → nested containers/widgets)
            if (!empty($el['elements']) && is_array($el['elements'])) {
                $this->extract_widgets($el['elements'], $widgets);
            }
        }
    }
}
