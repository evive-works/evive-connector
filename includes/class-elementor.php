<?php
if (!defined('ABSPATH')) exit;

class Evive_Elementor {

    public function register_routes() {
        register_rest_route('evive/v1', '/item/(?P<post_type>[a-zA-Z0-9_-]+)/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_item'],
            'permission_callback' => function () { return current_user_can('edit_posts'); },
            'args' => [
                'post_type' => ['required' => true, 'sanitize_callback' => 'sanitize_key'],
                'id'        => ['required' => true, 'validate_callback' => function ($v) { return is_numeric($v); }],
            ],
        ]);

        register_rest_route('evive/v1', '/elementor/inject-text', [
            'methods'             => 'POST',
            'callback'            => [$this, 'inject_text'],
            'permission_callback' => function () { return current_user_can('edit_posts'); },
        ]);

        register_rest_route('evive/v1', '/elementor/update-widget', [
            'methods'             => 'POST',
            'callback'            => [$this, 'update_widget'],
            'permission_callback' => function () { return current_user_can('edit_posts'); },
        ]);

        register_rest_route('evive/v1', '/search-text', [
            'methods'             => 'GET',
            'callback'            => [$this, 'search_text'],
            // evive #372: was public and leaked text of draft/private pages; now needs edit rights on that page.
            'permission_callback' => function (WP_REST_Request $request) {
                $id = (int) $request->get_param('page_id');
                return $id > 0 && current_user_can('edit_post', $id);
            },
            'args' => [
                'page_id' => ['required' => true, 'validate_callback' => function ($v) { return is_numeric($v); }],
                'query'   => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);
    }

    public function get_item(WP_REST_Request $request) {
        $post_type = $request->get_param('post_type');
        $id        = (int) $request->get_param('id');

        $post = get_post($id);
        if (!$post || $post->post_type !== $post_type) {
            return new WP_Error('not_found', 'Item not found', ['status' => 404]);
        }

        $meta        = get_post_meta($id);
        $elem_raw    = $meta['_elementor_data'][0] ?? null;
        $thumb_id    = (int) get_post_thumbnail_id($id);

        $result = [
            'id'                => $post->ID,
            'post_type'         => $post->post_type,
            'title'             => get_the_title($post),
            'post_content'      => $post->post_content,
            'status'            => $post->post_status,
            'date'              => $post->post_date,
            'slug'              => $post->post_name,
            'featured_image_id' => $thumb_id ?: null,
            'featured_image_url'=> $thumb_id ? get_the_post_thumbnail_url($id, 'full') : null,
            'taxonomies'        => $this->get_taxonomies($id, $post_type),
            'meta'              => $this->get_public_meta($meta),
        ];

        if (defined('WPSEO_VERSION') || class_exists('WPSEO_Options')) {
            $result['yoast'] = [
                'title'       => $meta['_yoast_wpseo_title'][0]    ?? null,
                'description' => $meta['_yoast_wpseo_metadesc'][0] ?? null,
                'focuskw'     => $meta['_yoast_wpseo_focuskw'][0]  ?? null,
            ];
        }

        if ($elem_raw) {
            $widgets    = json_decode($elem_raw, true) ?: [];
            $widget_map = [];
            $text_parts = [];
            $this->extract_widgets($widgets, '', $widget_map, $text_parts);
            $result['elementor'] = [
                'raw'          => $elem_raw,
                'text_content' => implode("\n\n", array_filter($text_parts)),
                'widget_map'   => $widget_map,
            ];
        }

        return rest_ensure_response($result);
    }

    private function get_taxonomies($post_id, $post_type) {
        $result    = [];
        $tax_names = get_object_taxonomies($post_type);
        foreach ($tax_names as $tax) {
            $terms = wp_get_post_terms($post_id, $tax, ['fields' => 'names']);
            if (!is_wp_error($terms) && !empty($terms)) {
                $result[$tax] = $terms;
            }
        }
        return $result;
    }

    private function get_public_meta(array $meta) {
        $result = [];
        foreach ($meta as $key => $values) {
            if (strpos($key, '_') === 0) continue;
            $result[$key] = $values[0] ?? null;
        }
        return $result;
    }

    /**
     * Extract editable text widgets from an Elementor-built post for the evive editor.
     * Returns null if the post has no Elementor data.
     * Returns ['html' => string, 'widget_map' => [{id, type}]] on success.
     */
    public static function build_editor_content(int $post_id): ?array {
        $raw = get_post_meta($post_id, '_elementor_data', true);
        if (!$raw || strlen(trim($raw)) < 5) return null;

        $elements = json_decode($raw, true);
        if (!is_array($elements)) return null;

        $map = [];
        self::_walk_for_editor($elements, $map);
        if (empty($map)) return null;

        $html = '';
        foreach ($map as $w) {
            if ($w['type'] === 'heading') {
                $html .= '<h2>' . esc_html($w['content']) . '</h2>';
            } elseif (in_array($w['type'], ['text-editor', 'text'], true)) {
                $c = $w['content'];
                // text-editor stores HTML; text is plain
                $html .= (strpos($c, '<') !== false) ? $c : '<p>' . esc_html($c) . '</p>';
            }
        }

        return [
            'html'       => $html,
            'widget_map' => array_map(function ($w) { return ['id' => $w['id'], 'type' => $w['type']]; }, $map),
        ];
    }

    // Walk Elementor tree, collecting heading/text widgets in document order.
    private static function _walk_for_editor(array $elements, array &$map): void {
        foreach ($elements as $el) {
            if (($el['elType'] ?? '') === 'widget') {
                $wtype = $el['widgetType'] ?? '';
                $s     = $el['settings']   ?? [];
                $id    = $el['id']         ?? null;
                if (!$id) { /* skip anonymous */ }
                elseif ($wtype === 'heading') {
                    $c = strip_tags($s['title'] ?? '');
                    if ($c !== '') $map[] = ['id' => $id, 'type' => $wtype, 'content' => $c];
                } elseif ($wtype === 'text-editor' || $wtype === 'text') {
                    $c = $s['editor'] ?? $s['text'] ?? '';
                    if ($c !== '') $map[] = ['id' => $id, 'type' => $wtype, 'content' => $c];
                }
            }
            if (!empty($el['elements'])) self::_walk_for_editor($el['elements'], $map);
        }
    }

    private function extract_widgets(array $elements, string $prefix, array &$widget_map, array &$text_parts) {
        foreach ($elements as $i => $el) {
            $pos  = $prefix !== '' ? "{$prefix}.{$i}" : (string) $i;
            $type = $el['elType'] ?? 'unknown';

            if ($type === 'widget') {
                $wtype    = $el['widgetType'] ?? 'unknown';
                $settings = $el['settings'] ?? [];
                $entry    = ['id' => $el['id'] ?? null, 'type' => $wtype, 'position' => $pos];

                // Curated shortcuts for the common native types, kept for callers already relying
                // on them -- content/image_id/image_url read faster than digging through settings
                // for the one field that matters 95% of the time.
                switch ($wtype) {
                    case 'heading':
                        $c = strip_tags($settings['title'] ?? '');
                        $entry['content'] = $c;
                        if ($c) $text_parts[] = $c;
                        break;
                    case 'text-editor':
                    case 'text':
                        $c = strip_tags($settings['editor'] ?? $settings['text'] ?? '');
                        $entry['content'] = $c;
                        if ($c) $text_parts[] = $c;
                        break;
                    case 'image':
                        $img = $settings['image'] ?? [];
                        $entry['image_id']  = $img['id']  ?? null;
                        $entry['image_url'] = $img['url'] ?? null;
                        break;
                    case 'button':
                        $entry['content'] = $settings['text'] ?? null;
                        break;
                }
                // Full settings for every widget type, not just unrecognized ones (card #79) --
                // previously only the "default"/unknown-type case got this, so style/advanced/
                // responsive fields on the five native types above were writable via update_widget
                // but never readable back through get_item, even after a real successful write.
                $entry['settings'] = $settings;

                $widget_map[] = $entry;
            } elseif (in_array($type, ['section', 'column', 'container'], true)) {
                // Column/section-level settings (content_position, background, gap, flex/grid
                // alignment) were completely invisible before -- this recursion reached their
                // CHILDREN but never recorded the container's own id/settings anywhere (card #86,
                // spun off from #25's audit). Same pattern as the card #79 widget-level fix: expose
                // the real settings so a caller can read current values before writing new ones,
                // rather than writing blind.
                $widget_map[] = [
                    'id' => $el['id'] ?? null,
                    'type' => $type,
                    'position' => $pos,
                    'settings' => $el['settings'] ?? [],
                ];
            }

            if (!empty($el['elements']) && is_array($el['elements'])) {
                $this->extract_widgets($el['elements'], $pos, $widget_map, $text_parts);
            }
        }
    }

    public function inject_text(WP_REST_Request $request) {
        $page_id  = (int) $request->get_param('page_id');
        $text     = (string) ($request->get_param('text') ?? '');
        $position = (string) ($request->get_param('inject_position') ?? 'before_first_section');
        $wtype    = (string) ($request->get_param('widget_type')     ?? 'text-editor');
        $css      = (string) ($request->get_param('css_class')       ?? 'evive-injected-text');

        $post = get_post($page_id);
        if (!$post) {
            return new WP_Error('not_found', 'Page not found', ['status' => 404]);
        }

        $raw      = get_post_meta($page_id, '_elementor_data', true) ?: '[]';
        $elements = json_decode($raw, true) ?: [];

        $wid     = 'evive_' . substr(md5(uniqid('', true)), 0, 7);
        $section = $this->build_section($wid, $wtype, $text, $css);

        if ($position === 'before_first_section') {
            array_unshift($elements, $section);
        } elseif ($position === 'after_last_section') {
            $elements[] = $section;
        } elseif (preg_match('/^(before|after)_section:(\d+)$/', $position, $m)) {
            $n   = (int) $m[2] - 1;
            $ins = $m[1] === 'after' ? $n + 1 : $n;
            array_splice($elements, max(0, $ins), 0, [$section]);
        } else {
            $elements[] = $section;
        }

        if (!evive_save_elementor_data($page_id, $elements)) {
            return new WP_Error('save_failed', 'Failed to encode Elementor data', ['status' => 500]);
        }

        wp_update_post(['ID' => $page_id, 'post_modified' => current_time('mysql'), 'post_modified_gmt' => current_time('mysql', true)]);
        evive_purge_post_cache($page_id);

        return rest_ensure_response([
            'success'   => true,
            'widget_id' => $wid,
            'page_id'   => $page_id,
            'message'   => "Text widget added: {$position}",
        ]);
    }

    private function build_section(string $wid, string $wtype, string $text, string $css): array {
        $settings = $wtype === 'heading'
            ? ['title' => $text, 'header_size' => 'h2', '_css_classes' => $css]
            : ['editor' => wpautop($text), '_css_classes' => $css];

        $resolved_type = $wtype === 'heading' ? 'heading' : 'text-editor';

        return [
            'id'       => $wid . '_s',
            'elType'   => 'section',
            'settings' => [],
            'elements' => [[
                'id'       => $wid . '_c',
                'elType'   => 'column',
                'settings' => ['_column_size' => 100],
                'elements' => [[
                    'id'         => $wid,
                    'elType'     => 'widget',
                    'widgetType' => $resolved_type,
                    'settings'   => $settings,
                    'elements'   => [],
                ]],
            ]],
        ];
    }

    public function update_widget(WP_REST_Request $request) {
        $page_id   = (int) $request->get_param('page_id');
        $widget_id = (string) ($request->get_param('widget_id') ?? '');
        $content   = $request->get_param('content');
        $fields    = $request->get_param('fields');

        if (is_string($fields)) {
            $fields = json_decode($fields, true) ?: null;
        }
        if ($content === null && empty($fields)) {
            return new WP_Error('missing_params', 'Provide "content" or "fields"', ['status' => 400]);
        }
        $use_fields = !empty($fields) && is_array($fields);
        if (!$use_fields) {
            $content = (string) $content;
        }

        $post = get_post($page_id);
        if (!$post) {
            return new WP_Error('not_found', 'Page not found', ['status' => 404]);
        }

        $raw      = get_post_meta($page_id, '_elementor_data', true) ?: '[]';
        $elements = json_decode($raw, true) ?: [];

        $widget_type = null;
        $normalized_fields = null;
        if ($use_fields) {
            // Look up the widget's current settings first, both to fail fast on an
            // unknown widget_id and so normalize_field_value() can tell a genuinely
            // scalar field apart from a structured one (array/object) that a caller
            // may have stringified — see normalize_field_value() docblock.
            $current_settings = $this->read_widget_settings($elements, $widget_id);
            if ($current_settings === null) {
                return new WP_Error('widget_not_found', "Widget {$widget_id} not found", ['status' => 404]);
            }
            $normalized_fields = [];
            foreach ($fields as $field => $value) {
                $existing = $current_settings[$field] ?? null;
                $normalized = $this->normalize_field_value((string) $field, $value, $existing);
                if (is_wp_error($normalized)) {
                    return $normalized;
                }
                $normalized_fields[$field] = $normalized;
            }

            // Verify any same-site URLs in the normalized values actually resolve, before
            // writing anything. This is what was missing on 2026-08-04/05: a caller (model
            // or otherwise) supplied a plausible-looking hosted_url for a real media ID, but
            // the URL pointed at the wrong month folder (/2026/05/ instead of the file's
            // real location /2026/08/) and 404'd. Nothing checked it, so it was written,
            // "verified" (structurally — the old bug), and reported as success — needing
            // four rounds of manual customer debugging to catch. Only same-site URLs are
            // checked (external providers like youtube/vimeo/dailymotion/videopress are out
            // of scope here and shouldn't block a save on a third party being slow/down).
            $url_issues = $this->verify_field_urls($normalized_fields);
            if (!empty($url_issues)) {
                return new WP_Error('url_unreachable',
                    'Refusing to save: one or more URLs in "fields" do not resolve (checked via HEAD/GET against this site). Nothing was written. Re-fetch the real URL for the intended media (e.g. via wp_list_media) rather than assuming/reconstructing a path.',
                    ['status' => 400, 'issues' => $url_issues]
                );
            }

            if (!$this->replace_widget_fields($elements, $widget_id, $normalized_fields, $widget_type)) {
                return new WP_Error('widget_not_found', "Widget {$widget_id} not found", ['status' => 404]);
            }
        } else {
            if (!$this->replace_widget_content($elements, $widget_id, $content, $widget_type)) {
                return new WP_Error('widget_not_found', "Widget {$widget_id} not found", ['status' => 404]);
            }
        }

        if (!evive_save_elementor_data($page_id, $elements)) {
            return new WP_Error('save_failed', 'Failed to encode Elementor data', ['status' => 500]);
        }

        global $wpdb;
        $saved_raw      = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_elementor_data' LIMIT 1",
            $page_id
        ));
        $saved_elements = $saved_raw ? (json_decode($saved_raw, true) ?: []) : [];

        if ($use_fields) {
            $saved_settings = $this->read_widget_settings($saved_elements, $widget_id);
            if ($saved_settings === null) {
                return new WP_Error('verify_failed',
                    "Widget {$widget_id} not found after save — data may be corrupted",
                    ['status' => 500]
                );
            }
            $mismatches = [];
            foreach ($normalized_fields as $field => $expected) {
                $actual = $saved_settings[$field] ?? null;
                // Structural comparison via canonical JSON, not a (string) cast — casting
                // an array to string collapses it to the literal word "Array" on both
                // sides, so any two different arrays/objects would falsely "match". This
                // is what let the original video-widget bug report success:true+verified:true
                // for a write that actually corrupted the field.
                if (!$this->values_match($actual, $expected)) {
                    $mismatches[$field] = ['expected' => $expected, 'saved' => $actual];
                }
            }
            if (!empty($mismatches)) {
                return new WP_Error('verify_mismatch',
                    'Write did not persist for one or more fields',
                    ['status' => 500, 'mismatches' => $mismatches]
                );
            }
        } else {
            $saved_content = $this->read_widget_content($saved_elements, $widget_id);
            if ($saved_content === null) {
                return new WP_Error('verify_failed',
                    "Widget {$widget_id} not found after save — data may be corrupted",
                    ['status' => 500]
                );
            }
            if ($saved_content !== $content) {
                return new WP_Error('verify_mismatch',
                    'Write did not persist: saved content does not match the requested update',
                    ['status' => 500, 'requested' => $content, 'saved' => $saved_content]
                );
            }
        }

        wp_update_post(['ID' => $page_id, 'post_modified' => current_time('mysql'), 'post_modified_gmt' => current_time('mysql', true)]);
        evive_purge_post_cache($page_id);

        return rest_ensure_response([
            'success'     => true,
            'widget_id'   => $widget_id,
            'widget_type' => $widget_type,
            'page_id'     => $page_id,
            'verified'    => true,
        ]);
    }

    public function search_text(WP_REST_Request $request) {
        $page_id = (int) $request->get_param('page_id');
        $query   = (string) ($request->get_param('query') ?? '');

        if (!$page_id || $query === '') {
            return new WP_Error('missing_params', 'page_id and query are required', ['status' => 400]);
        }

        $post = get_post($page_id);
        if (!$post) {
            return new WP_Error('not_found', 'Page not found', ['status' => 404]);
        }

        $raw      = get_post_meta($page_id, '_elementor_data', true) ?: '[]';
        $elements = json_decode($raw, true) ?: [];

        $matches = [];
        $this->search_widgets($elements, $query, $matches);

        return rest_ensure_response([
            'page_id'     => $page_id,
            'query'       => $query,
            'matches'     => $matches,
            'match_count' => count($matches),
        ]);
    }

    private function search_widgets(array $elements, string $query, array &$matches) {
        foreach ($elements as $el) {
            if (($el['elType'] ?? '') === 'widget') {
                $widget_id   = $el['id']         ?? '';
                $widget_type = $el['widgetType'] ?? '';
                $settings    = $el['settings']   ?? [];

                foreach ($settings as $field => $value) {
                    if (!is_string($value) || $value === '') continue;
                    $plain = wp_strip_all_tags($value);
                    if (stripos($plain, $query) !== false) {
                        $matches[] = [
                            'widget_id'   => $widget_id,
                            'widget_type' => $widget_type,
                            'field'       => $field,
                            'value'       => $this->excerpt($plain, $query),
                        ];
                    }
                }
            }

            if (!empty($el['elements']) && is_array($el['elements'])) {
                $this->search_widgets($el['elements'], $query, $matches);
            }
        }
    }

    private function excerpt(string $text, string $query, int $max = 120): string {
        $pos = stripos($text, $query);
        if ($pos === false || strlen($text) <= $max) return $text;

        $start  = max(0, $pos - 40);
        $length = min(strlen($text) - $start, $max);
        $slice  = substr($text, $start, $length);
        $prefix = $start > 0 ? '...' : '';
        $suffix = ($start + $length) < strlen($text) ? '...' : '';

        return $prefix . $slice . $suffix;
    }

    private function replace_widget_fields(array &$elements, string $target_id, array $fields, ?string &$widget_type = null): bool {
        for ($i = 0, $n = count($elements); $i < $n; $i++) {
            $elType = $elements[$i]['elType'] ?? '';
            // Widened beyond 'widget' to also accept column/section/container (card #86) -- these
            // never have a widgetType of their own, so $widget_type falls back to the elType itself
            // (e.g. 'section') for the response, matching what a caller would expect to see back.
            if (($elements[$i]['id'] ?? '') === $target_id && in_array($elType, ['widget', 'section', 'column', 'container'], true)) {
                $widget_type = $elType === 'widget' ? ($elements[$i]['widgetType'] ?? '') : $elType;
                foreach ($fields as $field => $value) {
                    $elements[$i]['settings'][$field] = $value;
                }
                return true;
            }
            if (!empty($elements[$i]['elements']) && is_array($elements[$i]['elements'])) {
                if ($this->replace_widget_fields($elements[$i]['elements'], $target_id, $fields, $widget_type)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Normalize/validate one incoming "fields" value against what's currently stored
     * for that setting. Elementor stores structured fields — media ({id,url,...}),
     * dimension controls ({unit,size,...}), repeaters (arrays of objects) — as real
     * nested arrays, never as strings. But callers (notably the MCP tool's older
     * string-only schema) sometimes JSON.stringify a structured value before sending
     * it, because the schema gave them no other way to express it. Passed straight
     * through, that string lands in $settings[$field] where Elementor's renderer
     * expects an array — the exact bug behind the liefdevoltrouwen.nl video widget
     * losing its video (2026-08-04): video_type stayed correct, hosted_url became an
     * unusable JSON string, and the old string-cast verification couldn't tell.
     *
     * Behavior:
     *  - If the existing value isn't structured (or doesn't exist yet), any scalar is
     *    accepted as-is — this keeps simple text fields (e.g. mae-headings sub-fields)
     *    working exactly as before.
     *  - If the incoming value is a string that parses as JSON into an array, use the
     *    parsed value — this auto-heals the common "caller stringified an object"
     *    case instead of just rejecting it.
     *  - If the existing value is structured (array) and, even after a parse attempt,
     *    the incoming value is still a scalar, reject with a 400 — refuse to silently
     *    overwrite a structured field with something Elementor can't render.
     *
     * @param mixed $value    Incoming value for this field.
     * @param mixed $existing Current value of this field on the live widget, or null.
     * @return mixed|WP_Error Normalized value to write, or a WP_Error to abort the request.
     */
    private function normalize_field_value(string $field, $value, $existing) {
        $existing_is_structured = is_array($existing);

        if (is_string($value)) {
            $trimmed         = ltrim($value);
            $looks_like_json = $trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[');

            if ($existing_is_structured || $looks_like_json) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $value = $decoded;
                }
            }
        }

        if ($existing_is_structured && !is_array($value)) {
            return new WP_Error(
                'field_type_mismatch',
                "Field \"{$field}\" currently holds a structured value (array/object) on this widget. "
                    . 'Refusing to overwrite it with a scalar/unparseable value — pass the real object or '
                    . 'array for this field, not a JSON-stringified string.',
                ['status' => 400, 'field' => $field]
            );
        }

        return $value;
    }

    /**
     * Structural equality for post-write verification. A (string) cast on an array
     * collapses to the literal word "Array" regardless of contents — two different
     * arrays would falsely compare equal. Comparing canonical JSON encodings instead
     * catches real mismatches (including type mismatches: an int and its string
     * equivalent no longer falsely match, either) while still tolerating key-order
     * differences that don't affect content, since both sides go through the same
     * decode → encode round trip.
     */
    private function values_match($a, $b): bool {
        return wp_json_encode($a) === wp_json_encode($b);
    }

    /**
     * Recursively scan a normalized fields map for same-site http(s) URLs and confirm
     * each one actually resolves, via evive_verify_same_site_url() (shared with
     * Evive_Page_Images::repoint_slideshow_image() — see that function's docblock for
     * why this lives in one shared place rather than being duplicated per call site).
     *
     * @return array List of {field, url, status} for every URL that failed to resolve.
     *               Empty array means everything checked out (or nothing needed checking).
     */
    private function verify_field_urls(array $fields): array {
        $issues = [];

        $walk = function ($value, string $path) use (&$walk, &$issues) {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    $walk($v, $path . '.' . $k);
                }
                return;
            }
            if (!is_string($value)) {
                return;
            }
            $result = evive_verify_same_site_url($value);
            if ($result['checked'] && !$result['ok']) {
                $issues[] = ['field' => $path, 'url' => $value, 'status' => $result['status']];
            }
        };

        foreach ($fields as $field => $value) {
            $walk($value, (string) $field);
        }

        return $issues;
    }

    private function read_widget_settings(array $elements, string $target_id): ?array {
        foreach ($elements as $el) {
            // Widened beyond 'widget' to also accept column/section/container (card #86) -- this
            // is called as a pre-check before replace_widget_fields() in update_widget(), so fixing
            // only replace_widget_fields() wasn't enough: this same restriction gated the request
            // and returned 404 before replace_widget_fields ever ran, confirmed live.
            if (($el['id'] ?? '') === $target_id && in_array($el['elType'] ?? '', ['widget', 'section', 'column', 'container'], true)) {
                return $el['settings'] ?? [];
            }
            if (!empty($el['elements'])) {
                $r = $this->read_widget_settings($el['elements'], $target_id);
                if ($r !== null) return $r;
            }
        }
        return null;
    }

    /**
     * Read the content field of a widget from a decoded elements tree.
     * Uses the same key-selection logic as replace_widget_content so that
     * the post-save verification compares the right field.
     * Returns null only if the widget was not found.
     */
    private function read_widget_content(array $elements, string $target_id): ?string {
        foreach ($elements as $el) {
            if (($el['id'] ?? '') === $target_id && ($el['elType'] ?? '') === 'widget') {
                $wtype = $el['widgetType'] ?? '';
                $s     = $el['settings'] ?? [];

                if ($wtype === 'heading') {
                    return isset($s['title']) ? (string) $s['title'] : '';
                }
                if (in_array($wtype, ['text-editor', 'text'], true)) {
                    if (isset($s['editor'])) return (string) $s['editor'];
                    if (isset($s['text']))   return (string) $s['text'];
                    return '';
                }
                if ($wtype === 'button') {
                    return isset($s['text']) ? (string) $s['text'] : '';
                }
                // Unknown widget type: check title (same key we write to)
                return isset($s['title']) ? (string) $s['title'] : '';
            }
            if (!empty($el['elements'])) {
                $r = $this->read_widget_content($el['elements'], $target_id);
                if ($r !== null) return $r;
            }
        }
        return null;
    }

    private function replace_widget_content(array &$elements, string $target_id, string $content, ?string &$widget_type = null): bool {
        for ($i = 0, $n = count($elements); $i < $n; $i++) {
            if (($elements[$i]['id'] ?? '') === $target_id && ($elements[$i]['elType'] ?? '') === 'widget') {
                $wtype       = $elements[$i]['widgetType'] ?? '';
                $widget_type = $wtype;

                if ($wtype === 'heading') {
                    $elements[$i]['settings']['title'] = $content;
                } elseif (in_array($wtype, ['text-editor', 'text'], true)) {
                    $elements[$i]['settings']['editor'] = $content;
                    $elements[$i]['settings']['text']   = $content;
                } elseif ($wtype === 'button') {
                    $elements[$i]['settings']['text'] = $content;
                } else {
                    $elements[$i]['settings']['title'] = $content;
                }
                return true;
            }
            if (!empty($elements[$i]['elements']) && is_array($elements[$i]['elements'])) {
                if ($this->replace_widget_content($elements[$i]['elements'], $target_id, $content, $widget_type)) {
                    return true;
                }
            }
        }
        return false;
    }
}
