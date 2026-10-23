<?php
if (!defined('ABSPATH')) exit;

class Evive_Clone {

    public function register_routes() {
        register_rest_route('evive/v1', '/clone/(?P<post_type>[a-zA-Z0-9_-]+)/(?P<source_id>\d+)', [
            'methods'             => 'POST',
            'callback'            => [$this, 'clone_item'],
            'permission_callback' => function () { return current_user_can('edit_posts'); },
            'args' => [
                'post_type' => ['required' => true, 'sanitize_callback' => 'sanitize_key'],
                'source_id' => ['required' => true, 'validate_callback' => function ($v) { return is_numeric($v); }],
            ],
        ]);

        register_rest_route('evive/v1', '/delete/(?P<post_type>[a-zA-Z0-9_-]+)/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [$this, 'delete_item'],
            'permission_callback' => function () { return current_user_can('delete_posts'); },
            'args' => [
                'post_type' => ['required' => true, 'sanitize_callback' => 'sanitize_key'],
                'id'        => ['required' => true, 'validate_callback' => function ($v) { return is_numeric($v); }],
            ],
        ]);
    }

    public function clone_item(WP_REST_Request $request) {
        $post_type = $request->get_param('post_type');
        $source_id = (int) $request->get_param('source_id');
        $body      = $request->get_json_params() ?: [];

        $source = get_post($source_id);
        if (!$source || $source->post_type !== $post_type) {
            return new WP_Error('not_found', 'Source item not found', ['status' => 404]);
        }

        $new_post = [
            'post_type'    => $post_type,
            'post_title'   => $body['title']    ?? ($source->post_title . ' (copy)'),
            'post_status'  => $body['status']   ?? 'draft',
            'post_date'    => $body['date']      ?? current_time('mysql'),
            'post_name'    => $body['slug']      ?? '',
            'post_content' => $body['post_content'] ?? '',
        ];

        $new_id = wp_insert_post($new_post, true);
        if (is_wp_error($new_id)) {
            return new WP_Error('insert_failed', $new_id->get_error_message(), ['status' => 500]);
        }

        // Clone source meta (skip Elementor and thumbnail — handled separately)
        $source_meta = get_post_meta($source_id);
        $skip_keys   = ['_elementor_data', '_elementor_css', '_elementor_element_cache', '_thumbnail_id', 'osf_portfolio_gallery', 'osf_portfolio_start_date'];
        foreach ($source_meta as $key => $values) {
            if (in_array($key, $skip_keys, true)) continue;
            foreach ($values as $v) {
                add_post_meta($new_id, $key, maybe_unserialize($v));
            }
        }

        // Handle Elementor: clone + apply replacements
        $elem_raw = $source_meta['_elementor_data'][0] ?? null;
        if ($elem_raw) {
            $elements = json_decode($elem_raw, true) ?: [];
            foreach ($body['elementor_replacements'] ?? [] as $rep) {
                if (is_array($rep)) {
                    $this->apply_replacement($elements, $rep);
                }
            }
            evive_save_elementor_data($new_id, $elements);
        }

        // Featured image
        if (!empty($body['featured_image_id'])) {
            set_post_thumbnail($new_id, (int) $body['featured_image_id']);
        }

        // Taxonomies — skip language/post_translations (handled via Polylang API below)
        $requested_lang_name = null;
        foreach ($body['taxonomies'] ?? [] as $taxonomy => $terms) {
            if (in_array($taxonomy, ['language', 'post_translations'], true)) {
                if ($taxonomy === 'language') {
                    $requested_lang_name = is_array($terms) ? ($terms[0] ?? null) : $terms;
                }
                continue;
            }
            if (!is_array($terms)) $terms = [$terms];
            $term_ids = [];
            foreach ($terms as $term_name) {
                $term = get_term_by('name', $term_name, $taxonomy)
                     ?: get_term_by('slug', $term_name, $taxonomy);
                if ($term) {
                    $term_ids[] = (int) $term->term_id;
                } else {
                    $created = wp_insert_term($term_name, $taxonomy);
                    if (!is_wp_error($created)) $term_ids[] = (int) $created['term_id'];
                }
            }
            if ($term_ids) wp_set_post_terms($new_id, $term_ids, $taxonomy);
        }

        // Language via Polylang API — always use pll_set_post_language so the
        // translation group (post_translations) is created and the correct
        // rewrite prefix is applied. Default: inherit the source item's language.
        $lang_slug = null;
        if (function_exists('pll_set_post_language')) {
            $lang_slug = pll_get_post_language($source_id, 'slug') ?: null;

            // Allow explicit override via body.language_slug (e.g. "nl", "en")
            if (!empty($body['language_slug'])) {
                $lang_slug = sanitize_key($body['language_slug']);
            } elseif ($requested_lang_name) {
                // Map name → slug using Polylang's own language list
                $valid_slugs = pll_languages_list(['fields' => 'slug']);
                $valid_names = pll_languages_list(['fields' => 'name']);
                if (is_array($valid_slugs) && is_array($valid_names)) {
                    $name_map = array_combine($valid_names, $valid_slugs);
                    if (isset($name_map[$requested_lang_name])) {
                        $lang_slug = $name_map[$requested_lang_name];
                    } elseif (in_array($requested_lang_name, $valid_slugs, true)) {
                        $lang_slug = $requested_lang_name;
                    }
                    // If the name doesn't map to a known language, keep source lang
                }
            }

            if ($lang_slug) {
                pll_set_post_language($new_id, $lang_slug);
            }
        }

        // Custom meta fields
        foreach ($body['meta'] ?? [] as $key => $value) {
            $meta_key = sanitize_key($key);
            // osf_portfolio_gallery: OSF expects a PHP associative array of id => url.
            // Claude passes a simple array of media IDs — look up each URL and rebuild.
            if ($meta_key === 'osf_portfolio_gallery' && is_array($value)) {
                $gallery = [];
                foreach ($value as $media_id) {
                    $mid = (int) $media_id;
                    if ($mid > 0) {
                        $url = wp_get_attachment_url($mid);
                        if ($url) $gallery[$mid] = $url;
                    }
                }
                if ($gallery) update_post_meta($new_id, 'osf_portfolio_gallery', $gallery);
                continue;
            }
            update_post_meta($new_id, $meta_key, $value);
        }

        // Yoast SEO fields
        $yoast_map = [
            'title'       => '_yoast_wpseo_title',
            'description' => '_yoast_wpseo_metadesc',
            'focuskw'     => '_yoast_wpseo_focuskw',
        ];
        foreach ($body['yoast'] ?? [] as $field => $value) {
            if (isset($yoast_map[$field])) {
                update_post_meta($new_id, $yoast_map[$field], $value);
            }
        }

        // Auto-set osf_portfolio_start_date from body date (MM/DD/YYYY format for OSF plugin)
        if (!empty($body['date'])) {
            $ts = strtotime($body['date']);
            if ($ts) {
                update_post_meta($new_id, 'osf_portfolio_start_date', date('m/d/Y', $ts));
            }
        }

        // Link as Polylang translation of another post (e.g. link EN clone to NL original)
        if (!empty($body['translation_of']) && function_exists('pll_save_post_translations')) {
            $ref_id = (int) $body['translation_of'];
            $existing = pll_get_post_translations($ref_id) ?: [];
            if ($lang_slug) $existing[$lang_slug] = $new_id;
            pll_save_post_translations($existing);
        }

        // Previously missing entirely on this path — matters when the clone is created
        // already published (or later published without another update_item call), same
        // reasoning as every other write path: see evive_purge_post_cache() docblock.
        evive_purge_post_cache($new_id);

        return rest_ensure_response([
            'success'      => true,
            'id'           => $new_id,
            'url'          => get_permalink($new_id),
            'edit_url'     => admin_url("post.php?post={$new_id}&action=edit"),
            'status'       => $new_post['post_status'],
            'language_slug'=> function_exists('pll_get_post_language') ? pll_get_post_language($new_id, 'slug') : null,
        ]);
    }

    public function delete_item(WP_REST_Request $request) {
        $post_type = $request->get_param('post_type');
        $id        = (int) $request->get_param('id');

        $post = get_post($id);
        if (!$post || $post->post_type !== $post_type) {
            return new WP_Error('not_found', 'Item not found', ['status' => 404]);
        }

        $result = wp_delete_post($id, true); // true = force delete, skip trash
        if (!$result) {
            return new WP_Error('delete_failed', 'Failed to delete item', ['status' => 500]);
        }

        return rest_ensure_response(['success' => true, 'id' => $id, 'deleted' => true]);
    }

    private function apply_replacement(array &$elements, array $rep): bool {
        $target_id = $rep['widget_id'] ?? null;
        if (!$target_id) return false;

        foreach ($elements as &$el) {
            if (($el['id'] ?? '') === $target_id && ($el['elType'] ?? '') === 'widget') {
                $wtype = $el['widgetType'] ?? '';

                if (isset($rep['content'])) {
                    if ($wtype === 'heading') {
                        $el['settings']['title'] = $rep['content'];
                    } elseif (in_array($wtype, ['text-editor', 'text'], true)) {
                        $el['settings']['editor'] = $rep['content'];
                        $el['settings']['text']   = $rep['content'];
                    } else {
                        $el['settings']['title'] = $rep['content'];
                    }
                }

                if (isset($rep['image_id'])) {
                    $img_url = wp_get_attachment_url($rep['image_id']) ?: '';
                    $el['settings']['image'] = ['id' => (int) $rep['image_id'], 'url' => $img_url];
                }

                return true;
            }

            if (!empty($el['elements']) && is_array($el['elements'])
                && $this->apply_replacement($el['elements'], $rep)) {
                return true;
            }
        }
        unset($el); // clear dangling foreach reference
        return false;
    }
}
