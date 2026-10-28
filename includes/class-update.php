<?php
if (!defined('ABSPATH')) exit;

/**
 * Generic post field updater for any post type, including CPTs not in WP REST.
 * Use this via the MCP wp_update_item tool for portfolio items, products, etc.
 */
class Evive_Update {

    public function register_routes() {
        register_rest_route('evive/v1', '/update/(?P<post_type>[a-zA-Z0-9_-]+)/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_item_content'],
            'permission_callback' => function () { return current_user_can('edit_posts'); },
            'args'                => [
                'post_type' => ['required' => true, 'sanitize_callback' => 'sanitize_key'],
                'id'        => ['required' => true, 'validate_callback' => function ($v) { return is_numeric($v); }],
            ],
        ]);

        register_rest_route('evive/v1', '/update/(?P<post_type>[a-zA-Z0-9_-]+)/(?P<id>\d+)', [
            'methods'             => 'POST',
            'callback'            => [$this, 'update_item'],
            'permission_callback' => function () { return current_user_can('edit_posts'); },
            'args'                => [
                'post_type' => ['required' => true, 'sanitize_callback' => 'sanitize_key'],
                'id'        => ['required' => true, 'validate_callback' => function ($v) { return is_numeric($v); }],
            ],
        ]);

        register_rest_route('evive/v1', '/purge-cache/(?P<id>\d+)', [
            'methods'             => 'POST',
            'callback'            => [$this, 'purge_cache'],
            'permission_callback' => function () { return current_user_can('edit_posts'); },
            'args'                => [
                'id' => ['required' => true, 'validate_callback' => function ($v) { return is_numeric($v); }],
            ],
        ]);
        register_rest_route('evive/v1', '/force-clear-cache', [
            'methods'             => 'POST',
            'callback'            => [$this, 'force_clear_cache'],
            'permission_callback' => 'evive_can_manage_site', // evive #373: site-wide cache
        ]);
    }

    public function get_item_content(WP_REST_Request $request) {
        $post_type = $request->get_param('post_type');
        $post_id   = (int) $request->get_param('id');
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('not_found', "No post with ID {$post_id} found.", ['status' => 404]);
        }
        if ($post->post_type !== $post_type) {
            return new WP_Error('type_mismatch',
                "Post {$post_id} is type '{$post->post_type}', not '{$post_type}'.",
                ['status' => 400]
            );
        }
        // Prefer Elementor widget content — post_content is ignored by Elementor for rendering.
        $elem = Evive_Elementor::build_editor_content($post_id);
        if ($elem !== null) {
            return rest_ensure_response([
                'post_id'    => $post_id,
                'post_type'  => $post_type,
                'title'      => get_the_title($post_id),
                'content'    => $elem['html'],
                'elementor'  => true,
                'widget_map' => $elem['widget_map'],
                'status'     => $post->post_status,
                'link'       => get_permalink($post_id),
            ]);
        }

        return rest_ensure_response([
            'post_id'   => $post_id,
            'post_type' => $post_type,
            'title'     => get_the_title($post_id),
            'content'   => apply_filters('the_content', $post->post_content),
            'status'    => $post->post_status,
            'link'      => get_permalink($post_id),
        ]);
    }

    public function update_item(WP_REST_Request $request) {
        $post_type = $request->get_param('post_type');
        $post_id   = (int) $request->get_param('id');
        $body      = $request->get_json_params() ?: [];

        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('not_found', "No post with ID {$post_id} found.", ['status' => 404]);
        }
        if ($post->post_type !== $post_type) {
            return new WP_Error('type_mismatch',
                "Post {$post_id} is type '{$post->post_type}', not '{$post_type}'.",
                ['status' => 400]
            );
        }

        $args = ['ID' => $post_id];

        if (isset($body['title']))   $args['post_title']   = sanitize_text_field($body['title']);
        if (isset($body['status']))  $args['post_status']  = sanitize_key($body['status']);
        if (isset($body['slug']))    $args['post_name']    = sanitize_title($body['slug']);
        if (isset($body['excerpt'])) $args['post_excerpt'] = sanitize_textarea_field($body['excerpt']);
        if (isset($body['content'])) $args['post_content'] = wp_kses_post($body['content']);
        if (isset($body['date'])) {
            // Accept ISO 8601: 2024-06-25 or 2024-06-25T10:00:00
            $parsed = strtotime($body['date']);
            if ($parsed === false) {
                return new WP_Error('invalid_date',
                    "Invalid date format '{$body['date']}'. Use ISO 8601, e.g. '2024-06-25' or '2024-06-25T10:00:00'.",
                    ['status' => 400]
                );
            }
            $args['post_date']     = date('Y-m-d H:i:s', $parsed);
            $args['post_date_gmt'] = get_gmt_from_date($args['post_date']);
        }

        $taxonomies = (isset($body['taxonomies']) && is_array($body['taxonomies'])) ? $body['taxonomies'] : null;

        if (count($args) <= 1 && !$taxonomies) {
            return new WP_Error('no_fields',
                'No fields provided. Send at least one of: title, date, status, slug, excerpt, content, taxonomies.',
                ['status' => 400]
            );
        }

        if (count($args) > 1) {
            $result = wp_update_post($args, true);
            if (is_wp_error($result)) {
                return new WP_Error('update_failed', $result->get_error_message(), ['status' => 500]);
            }
        }

        // Set custom (or built-in) taxonomy terms directly on the existing post — the
        // gap that led to a portfolio item being deleted and recreated on 2026-08-05
        // just to change its category. Terms are resolved by name or slug (matching
        // Evive_Clone's approach), or accepted as a numeric term ID directly. Unlike
        // clone, an unrecognized term name is a hard error here rather than silently
        // creating a new term — a typo on a live item's category should surface
        // immediately, not quietly spawn an unused near-duplicate term.
        $taxonomies_set = [];
        if ($taxonomies) {
            foreach ($taxonomies as $taxonomy => $terms) {
                if (in_array($taxonomy, ['language', 'post_translations'], true)) {
                    return new WP_Error('unsupported_taxonomy',
                        "Taxonomy \"{$taxonomy}\" is not supported via update_item — language changes need a Polylang-aware tool (see evive_clone_item's language handling), not a plain term set.",
                        ['status' => 400]
                    );
                }
                if (!taxonomy_exists($taxonomy)) {
                    return new WP_Error('unknown_taxonomy', "Taxonomy \"{$taxonomy}\" does not exist on this site.", ['status' => 400]);
                }

                $term_list  = is_array($terms) ? $terms : [$terms];
                $term_ids   = [];
                $unresolved = [];
                foreach ($term_list as $term_ref) {
                    if (is_numeric($term_ref) && get_term((int) $term_ref, $taxonomy) instanceof WP_Term) {
                        $term_ids[] = (int) $term_ref;
                        continue;
                    }
                    $term = get_term_by('name', (string) $term_ref, $taxonomy) ?: get_term_by('slug', (string) $term_ref, $taxonomy);
                    if ($term) {
                        $term_ids[] = (int) $term->term_id;
                    } else {
                        $unresolved[] = $term_ref;
                    }
                }

                if ($unresolved) {
                    return new WP_Error('term_not_found',
                        'Could not find an existing term in taxonomy "' . $taxonomy . '" for: ' . implode(', ', $unresolved)
                            . '. Check the exact spelling (e.g. via evive_get_item on a similar item that already has the right term) — terms are not auto-created on update.',
                        ['status' => 400, 'taxonomy' => $taxonomy, 'unresolved' => $unresolved]
                    );
                }

                $set = wp_set_post_terms($post_id, $term_ids, $taxonomy);
                if (is_wp_error($set)) {
                    return new WP_Error('taxonomy_update_failed', $set->get_error_message(), ['status' => 500, 'taxonomy' => $taxonomy]);
                }

                // Verify it actually persisted — re-read rather than trust wp_set_post_terms's
                // return value alone, matching the discipline from the 2026-08-04/05 fixes:
                // a call reporting success is not the same as the data actually being correct.
                $saved_ids = wp_get_post_terms($post_id, $taxonomy, ['fields' => 'ids']);
                sort($term_ids); sort($saved_ids);
                if (is_wp_error($saved_ids) || $saved_ids !== $term_ids) {
                    return new WP_Error('verify_failed',
                        "Wrote taxonomy \"{$taxonomy}\" but the saved terms don't match what was requested — refusing to report success.",
                        ['status' => 500, 'taxonomy' => $taxonomy, 'requested' => $term_ids, 'saved' => is_wp_error($saved_ids) ? null : $saved_ids]
                    );
                }

                $taxonomies_set[$taxonomy] = wp_get_post_terms($post_id, $taxonomy, ['fields' => 'names']);
            }
        }

        evive_purge_post_cache($post_id);
        $updated = get_post($post_id);
        return rest_ensure_response([
            'success'    => true,
            'id'         => $post_id,
            'post_type'  => $post_type,
            'title'      => get_the_title($post_id),
            'status'     => $updated->post_status,
            'slug'       => $updated->post_name,
            'date'       => $updated->post_date,
            'link'       => get_permalink($post_id),
            'taxonomies' => $taxonomies_set,
        ]);
    }

    /**
     * Bare cache-purge endpoint for content-write paths that don't go through this plugin
     * at all — namely wp_update_page / wp_update_post / wp_update_item's WP-REST branch in
     * wordpress-mcp, which write directly to WP core's REST API and previously had no purge
     * anywhere in their call path. That's the actual root cause behind the 2026-08-07 "still
     * needed a manual Clear Files & Data" report: the purge logic itself (evive_purge_post_cache)
     * was already correct and already called by every plugin-owned write path — these three
     * generic fallback tools just never reached it. Called as a best-effort follow-up after a
     * successful core-REST write; the caller treats a failure here as non-fatal (old plugin
     * version, or plugin not installed) since the underlying content write already succeeded.
     */
    public function purge_cache(WP_REST_Request $request) {
        $post_id = (int) $request->get_param('id');
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('not_found', "No post with ID {$post_id} found.", ['status' => 404]);
        }
        evive_purge_post_cache($post_id);
        return rest_ensure_response(['success' => true, 'id' => $post_id]);
    }


    /**
     * Deliberate blunt fallback for when the user directly asks to clear the cache and
     * evive has no better-targeted answer yet. Calls Elementor's own
     * Plugin::$instance->files_manager->clear_cache() directly -- the exact method
     * Elementor's "Clear Files & Data" button itself calls -- rather than just firing the
     * do_action('elementor/core/files/clear_cache') notification hook that method fires as
     * its OWN last step. Confirmed via card #24 that firing only the trailing hook was the
     * actual bug: LiteSpeed's listener genuinely receives it and purges its own cache
     * correctly, but that's pointless if Elementor's own physical CSS/asset files and
     * postmeta were never regenerated in the first place -- there was nothing fresh for
     * LiteSpeed to have cached. This call replicates the button's real work (site-wide
     * postmeta + physical file cleanup and regeneration), and fires that same hook itself
     * as part of doing so, so LiteSpeed's purge still happens too, just now for a reason.
     * Guarded with class_exists/isset so this degrades safely if Elementor isn't active.
     * Also fires the equivalent full-flush hooks for WP Rocket and W3TC directly, in case a
     * site runs one of those instead of LiteSpeed. NOT called automatically on routine
     * edits -- only when the user explicitly asks, matching the same reasoning in
     * evive_purge_post_cache() for staying scoped by default: a full site-wide regen is
     * real, avoidable cost that shouldn't happen on every small edit, but is exactly the
     * right tool when evive's structured cache logic hasn't caught up with a specific
     * widget/template/plugin combination yet.
     */
    public function force_clear_cache(WP_REST_Request $request) {
        $debug = evive_purge_all_caches();

        return rest_ensure_response([
            'success' => true,
            'message' => 'Cleared the Elementor site-wide file/data cache and notified page-cache plugins (LiteSpeed, WP Rocket, W3TC) to do a full purge -- the same effect as clicking Elementor > Tools > Clear Files and Data.',
            'debug'   => $debug,
        ]);
    }
}