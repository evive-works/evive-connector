<?php
if (!defined('ABSPATH')) exit;

/**
 * Elementor's global "kit" settings -- site-wide colors, typography, site name/logo.
 * Card #88, spun off from #25's audit: no route anywhere in the plugin touched this before.
 *
 * Scope note: header/footer template assignment and "global widgets" (both mentioned in
 * the card) are NOT included here. Checked live on liefdevoltrouwen.nl -- its header/footer
 * templates have no _elementor_conditions meta at all (the mechanism assumed in the card),
 * and _elementor_template_type on the header template is "page", not "header" -- template
 * assignment on this site does not work the way Elementor Pro's native Theme Builder
 * conditions normally would, and needs its own separate investigation rather than a guess.
 * Global widgets are unverifiable here too -- the kit's own _elementor_data is empty ([])
 * on this site, meaning the feature is not in use anywhere to confirm a real schema against.
 * This card covers the part with a real, live-verified schema: system_colors, custom_colors,
 * system_typography, custom_typography, site_name, site_logo.
 */
class Evive_Kit {

    public function register_routes() {
        $auth = function () { return current_user_can('edit_posts'); };

        register_rest_route('evive/v1', '/elementor/kit', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_kit'],
            'permission_callback' => $auth,
        ]);
        register_rest_route('evive/v1', '/elementor/update-kit', [
            'methods'             => 'POST',
            'callback'            => [$this, 'update_kit'],
            'permission_callback' => $auth,
        ]);
    }

    private function active_kit_id() {
        $kit_id = (int) get_option('elementor_active_kit');
        return $kit_id ?: null;
    }

    public function get_kit(WP_REST_Request $request) {
        $kit_id = $this->active_kit_id();
        if (!$kit_id) {
            return new WP_Error('no_kit', 'No active Elementor kit found on this site.', ['status' => 404]);
        }
        $settings = get_post_meta($kit_id, '_elementor_page_settings', true);
        if (!is_array($settings)) $settings = [];

        return rest_ensure_response([
            'kit_id'             => $kit_id,
            'system_colors'      => $settings['system_colors'] ?? [],
            'custom_colors'      => $settings['custom_colors'] ?? [],
            'system_typography'  => $settings['system_typography'] ?? [],
            'custom_typography'  => $settings['custom_typography'] ?? [],
            'site_name'          => $settings['site_name'] ?? null,
            'site_logo'          => $settings['site_logo'] ?? null,
        ]);
    }

    // Elementor's system colors/typography are a fixed set (primary/secondary/text/accent
    // for colors, same four for typography on a default install) identified by a stable
    // _id -- the UI only lets you edit their color/font values, never add or remove entries.
    // custom_colors/custom_typography are a real open-ended list the UI lets you add to.
    // Mirrors that distinction here rather than treating all four arrays the same way.
    const SYSTEM_KEYS = ['system_colors', 'system_typography'];
    const CUSTOM_KEYS = ['custom_colors', 'custom_typography'];

    public function update_kit(WP_REST_Request $request) {
        $kit_id = $this->active_kit_id();
        if (!$kit_id) {
            return new WP_Error('no_kit', 'No active Elementor kit found on this site.', ['status' => 404]);
        }
        $settings = get_post_meta($kit_id, '_elementor_page_settings', true);
        if (!is_array($settings)) $settings = [];

        $body = $request->get_json_params() ?: [];

        foreach (array_merge(self::SYSTEM_KEYS, self::CUSTOM_KEYS) as $key) {
            if (!isset($body[$key])) continue;
            if (!is_array($body[$key])) {
                return new WP_Error('bad_field', "{$key} must be an array of entries", ['status' => 400]);
            }
            $result = $this->merge_entries($key, $settings[$key] ?? [], $body[$key], in_array($key, self::SYSTEM_KEYS, true));
            if (is_wp_error($result)) return $result;
            $settings[$key] = $result;
        }

        if (isset($body['site_name'])) {
            $settings['site_name'] = (string) $body['site_name'];
        }
        if (isset($body['site_logo'])) {
            $attachment_id = is_array($body['site_logo']) ? (int) ($body['site_logo']['id'] ?? 0) : (int) $body['site_logo'];
            $url = $attachment_id ? wp_get_attachment_url($attachment_id) : false;
            if (!$url) {
                return new WP_Error('bad_logo', "site_logo must be a valid attachment id (got {$attachment_id})", ['status' => 400]);
            }
            $settings['site_logo'] = ['id' => $attachment_id, 'url' => $url, 'size' => '', 'alt' => '', 'source' => 'library'];
        }

        update_post_meta($kit_id, '_elementor_page_settings', $settings);
        // Site-wide on purpose: kit colors/fonts are rendered via CSS variables present on
        // every page, unlike a normal single-post edit -- see evive_purge_all_caches() docblock.
        $cache_debug = evive_purge_all_caches();

        return rest_ensure_response([
            'success' => true,
            'kit_id'  => $kit_id,
            'settings'=> [
                'system_colors'     => $settings['system_colors'] ?? [],
                'custom_colors'     => $settings['custom_colors'] ?? [],
                'system_typography' => $settings['system_typography'] ?? [],
                'custom_typography' => $settings['custom_typography'] ?? [],
                'site_name'         => $settings['site_name'] ?? null,
                'site_logo'         => $settings['site_logo'] ?? null,
            ],
            'cache' => $cache_debug,
        ]);
    }

    /**
     * Merge incoming partial entries into an existing _id-keyed array (system_colors,
     * custom_colors, system_typography, or custom_typography). Existing _id -> shallow
     * merge onto that entry. Unknown _id: rejected for system_* (fixed set, editing only);
     * appended as a new entry (auto-generated _id if none given) for custom_*.
     */
    private function merge_entries(string $key, array $existing, array $incoming, bool $is_system) {
        foreach ($incoming as $entry) {
            if (!is_array($entry)) continue;
            $id = $entry['_id'] ?? null;
            $found = false;
            foreach ($existing as &$e) {
                if ($id && ($e['_id'] ?? null) === $id) {
                    $e = array_merge($e, $entry);
                    $found = true;
                    break;
                }
            }
            unset($e);
            if ($found) continue;

            if ($is_system) {
                return new WP_Error(
                    'unknown_system_id',
                    "No {$key} entry with _id \"{$id}\" -- system colors/fonts are a fixed set (usually primary/secondary/text/accent), edit an existing one rather than adding. Call GET /elementor/kit first to see the real current _ids.",
                    ['status' => 400]
                );
            }
            if (empty($entry['title'])) {
                return new WP_Error('missing_title', "New {$key} entries need a title", ['status' => 400]);
            }
            $entry['_id'] = $id ?: substr(md5(uniqid((string) wp_rand(), true)), 0, 7);
            $existing[] = $entry;
        }
        return $existing;
    }
}
