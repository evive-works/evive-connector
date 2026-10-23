<?php
if (!defined('ABSPATH')) exit;

class Evive_Page_Images {

    public function register_routes() {
        $auth = function () { return current_user_can('edit_posts'); };

        register_rest_route('evive/v1', '/front-page-id', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_front_page_id'],
            'permission_callback' => $auth,
        ]);

        register_rest_route('evive/v1', '/page-images/(?P<page_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_page_images'],
            'permission_callback' => $auth,
        ]);

        register_rest_route('evive/v1', '/elementor/repoint-slideshow-image', [
            'methods'             => 'POST',
            'callback'            => [$this, 'repoint_slideshow_image'],
            'permission_callback' => $auth,
        ]);
    }

    // ── GET /evive/v1/front-page-id ─────────────────────────────────────────
    public function get_front_page_id($request) {
        $id = intval(get_option('page_on_front'));
        if (!$id) {
            return rest_ensure_response(['front_page_id' => null, 'note' => 'Site is not set to show a static front page.']);
        }
        $post = get_post($id);
        return rest_ensure_response([
            'front_page_id' => $id,
            'title'         => $post ? get_the_title($post) : '',
        ]);
    }

    // ── GET /evive/v1/page-images/{page_id} ──────────────────────────────────
    public function get_page_images($request) {
        $page_id = intval($request['page_id']);
        $post = get_post($page_id);
        if (!$post) {
            return new WP_Error('not_found', 'Page not found.', ['status' => 404]);
        }

        $raw = get_post_meta($page_id, '_elementor_data', true);
        if (!$raw) {
            return rest_ensure_response(['page_id' => $page_id, 'slideshow_images' => [], 'note' => 'No Elementor data found.']);
        }

        $elements = json_decode($raw, true);
        if (!is_array($elements)) {
            return new WP_Error('parse_error', 'Could not parse Elementor data.', ['status' => 500]);
        }

        $images = [];
        $position = 1;
        $this->collect_slideshow_images($elements, $images, $position, 'Hero');

        return rest_ensure_response([
            'page_id'          => $page_id,
            'title'            => get_the_title($post),
            'slideshow_images' => $images,
        ]);
    }

    private function collect_slideshow_images(array $elements, array &$images, int &$position, string $location_hint) {
        foreach ($elements as $index => $el) {
            if (!is_array($el)) continue;

            $settings = $el['settings'] ?? [];
            if (($settings['background_background'] ?? '') === 'slideshow') {
                $gallery = $settings['background_slideshow_gallery'] ?? [];
                $section_label = $location_hint . ' slideshow';
                foreach ($gallery as $gi => $entry) {
                    $att_id    = intval($entry['id'] ?? 0);
                    $thumb_src = $att_id ? wp_get_attachment_image_src($att_id, 'medium') : null;
                    $images[]  = [
                        'position'      => $position++,
                        'attachment_id' => $att_id,
                        'url'           => $entry['url'] ?? '',
                        'thumbnail_url' => ($thumb_src && $thumb_src[0]) ? $thumb_src[0] : ($entry['url'] ?? ''),
                        'location'      => $section_label,
                        'element_id'    => $el['id'] ?? '',
                        'gallery_index' => $gi,
                    ];
                }
            }

            foreach (['elements', 'children'] as $child_key) {
                if (!empty($el[$child_key])) {
                    $this->collect_slideshow_images($el[$child_key], $images, $position, $location_hint);
                }
            }
        }
    }

    // ── POST /evive/v1/elementor/repoint-slideshow-image ─────────────────────
    public function repoint_slideshow_image($request) {
        $page_id           = intval($request->get_param('page_id') ?? 0);
        $element_id        = sanitize_text_field($request->get_param('element_id') ?? '');
        $gallery_index     = intval($request->get_param('gallery_index') ?? -1);
        $new_attachment_id = intval($request->get_param('new_attachment_id') ?? 0);
        $new_url           = esc_url_raw($request->get_param('new_url') ?? '');

        if (!$page_id || !$element_id || $gallery_index < 0 || !$new_attachment_id || !$new_url) {
            return new WP_Error('missing_params', 'page_id, element_id, gallery_index, new_attachment_id, and new_url are all required.', ['status' => 400]);
        }

        // Confirm the new image actually exists at this URL before writing it anywhere —
        // same gap, same fix as evive_update_widget's verify_field_urls(): a caller-supplied
        // URL (e.g. guessing the wrong month's upload folder) was previously saved with zero
        // check. evive_verify_same_site_url() only checks URLs on this site's own host.
        $url_check = evive_verify_same_site_url($new_url);
        if (!$url_check['ok']) {
            return new WP_Error('url_unreachable',
                'Refusing to save: new_url does not resolve (checked via HEAD/GET against this site). Nothing was written. Re-fetch the real URL for the intended image (e.g. via wp_list_media or list_page_images) rather than assuming/reconstructing a path.',
                ['status' => 400, 'field' => 'new_url', 'url' => $new_url, 'http_status' => $url_check['status']]
            );
        }

        $post = get_post($page_id);
        if (!$post) {
            return new WP_Error('page_not_found', 'Page not found.', ['status' => 404]);
        }

        $raw = get_post_meta($page_id, '_elementor_data', true);
        if (!$raw) {
            return new WP_Error('no_elementor_data', 'Page has no Elementor data.', ['status' => 404]);
        }

        $elements = json_decode($raw, true);
        if (!is_array($elements)) {
            return new WP_Error('parse_error', 'Could not parse Elementor data.', ['status' => 500]);
        }

        // Walk the tree and swap the reference; works in-place via reference
        $result = $this->find_and_repoint($elements, $element_id, $gallery_index, $new_attachment_id, $new_url);

        if (!$result['found']) {
            return new WP_Error(
                'not_found',
                'Could not find a slideshow gallery entry at element_id=' . $element_id . ' gallery_index=' . $gallery_index . '. Reason: ' . ($result['reason'] ?? 'unknown') . '. No changes made.',
                ['status' => 404]
            );
        }

        // Save the updated Elementor data
        if (!evive_save_elementor_data($page_id, $elements)) {
            return new WP_Error('save_failed', 'Failed to save updated Elementor data.', ['status' => 500]);
        }

        // Clear Elementor and page caches — shared with every other write path, see
        // evive_purge_post_cache()'s docblock (class-meta.php) for why this is scoped
        // per-post rather than a site-wide clear.
        evive_purge_post_cache($page_id);

        return rest_ensure_response([
            'success'           => true,
            'page_id'           => $page_id,
            'element_id'        => $element_id,
            'gallery_index'     => $gallery_index,
            'old_attachment_id' => $result['old_id'],
            'new_attachment_id' => $new_attachment_id,
            'message'           => 'Slideshow image at position ' . ($gallery_index + 1) . ' updated.',
        ]);
    }

    private function find_and_repoint(array &$elements, string $element_id, int $gallery_index, int $new_attachment_id, string $new_url): array {
        foreach ($elements as &$el) {
            if (!is_array($el)) continue;

            if (isset($el['id']) && $el['id'] === $element_id) {
                $gallery = $el['settings']['background_slideshow_gallery'] ?? null;
                if (!is_array($gallery)) {
                    return ['found' => false, 'reason' => 'element_has_no_slideshow_gallery'];
                }
                if ($gallery_index < 0 || $gallery_index >= count($gallery)) {
                    return ['found' => false, 'reason' => 'gallery_index_out_of_range'];
                }
                $old_id  = intval($el['settings']['background_slideshow_gallery'][$gallery_index]['id'] ?? 0);
                $el['settings']['background_slideshow_gallery'][$gallery_index] = [
                    'id'  => $new_attachment_id,
                    'url' => $new_url,
                ];
                return ['found' => true, 'old_id' => $old_id];
            }

            foreach (['elements', 'children'] as $child_key) {
                if (!empty($el[$child_key])) {
                    $result = $this->find_and_repoint($el[$child_key], $element_id, $gallery_index, $new_attachment_id, $new_url);
                    if ($result['found'] || ($result['reason'] ?? '') !== 'not_found') {
                        return $result;
                    }
                }
            }
        }
        return ['found' => false, 'reason' => 'not_found'];
    }
}
