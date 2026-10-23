<?php
if (!defined('ABSPATH')) exit;

class Evive_Content_Map {

    public function register_routes() {
        register_rest_route('evive/v1', '/content-map', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_content_map'],
            'permission_callback' => function () { return current_user_can('edit_posts'); },
        ]);
    }

    public function get_content_map() {
        $cached = get_transient('evive_content_map');
        if ($cached) {
            // plugin_version always injected fresh — never served from the 1-hour transient
            $cached['plugin_version'] = defined('EVIVE_VERSION') ? EVIVE_VERSION : null;
            return rest_ensure_response($cached);
        }

        $raw_types  = get_post_types(['public' => true], 'objects');
        $post_types = [];

        foreach ($raw_types as $pt) {
            if ($pt->name === 'attachment') continue;

            $counts    = wp_count_posts($pt->name);
            $count     = (int) ($counts->publish ?? 0);
            $sample_id = null;
            $has_elem  = false;
            $custom_fields = [];

            $samples = get_posts([
                'post_type'   => $pt->name,
                'numberposts' => 1,
                'post_status' => 'publish',
                'fields'      => 'ids',
            ]);

            if (!empty($samples)) {
                $sample_id = (int) $samples[0];
                $elem_data = get_post_meta($sample_id, '_elementor_data', true);
                $has_elem  = !empty($elem_data) && $elem_data !== '[]';

                global $wpdb;
                $keys = $wpdb->get_col($wpdb->prepare(
                    "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} pm
                     JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                     WHERE p.post_type = %s
                     AND meta_key NOT LIKE '\\_%'
                     LIMIT 20",
                    $pt->name
                ));
                $custom_fields = array_values($keys);
            }

            $entry = [
                'name'          => $pt->name,
                'label'         => $pt->label,
                'rest_base'     => $pt->rest_base ?: $pt->name,
                'has_elementor' => $has_elem,
                'count'         => $count,
                'sample_id'     => $sample_id,
            ];
            if (!empty($custom_fields)) $entry['custom_fields'] = $custom_fields;
            $post_types[] = $entry;
        }

        $raw_taxes  = get_taxonomies(['public' => true], 'objects');
        $taxonomies = [];
        foreach ($raw_taxes as $tax) {
            $taxonomies[] = [
                'name'       => $tax->name,
                'label'      => $tax->label,
                'post_types' => array_values($tax->object_type),
            ];
        }

        $result = [
            'post_types'         => $post_types,
            'taxonomies'         => $taxonomies,
            'elementor_active'   => defined('ELEMENTOR_VERSION'),
            'elementor_version'  => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : null,
            'acf_active'         => defined('ACF_VERSION') || function_exists('get_field'),
            'yoast_active'       => defined('WPSEO_VERSION') || class_exists('WPSEO_Options'),
            'woocommerce_active' => defined('WC_VERSION') || class_exists('WooCommerce'),
            'revslider_active'   => class_exists('RevSlider') || class_exists('RevSliderFront') || class_exists('UniteFunctionsRev'),
            'revslider_version'  => (defined('RS_REVISION') ? RS_REVISION : get_option('revslider-version')) ?: null,
            'site_url'           => get_site_url(),
            'site_name'          => get_bloginfo('name'),
            'wordpress_version'  => get_bloginfo('version'),
        ];

        set_transient('evive_content_map', $result, HOUR_IN_SECONDS);

        // Inject fresh after caching so it's always current
        $result['plugin_version'] = defined('EVIVE_VERSION') ? EVIVE_VERSION : null;
        return rest_ensure_response($result);
    }
}
