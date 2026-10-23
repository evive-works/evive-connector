<?php
if (!defined('ABSPATH')) exit;

/**
 * Standalone SEO field editor.
 * Updates Yoast meta on EXISTING items without creating copies.
 * Use evive_clone_item only for creating new content.
 */
class Evive_Seo {

    public function register_routes() {
        register_rest_route('evive/v1', '/seo/(?P<post_type>[a-zA-Z0-9_-]+)/(?P<id>\d+)', [
            'methods'             => 'POST',
            'callback'            => [$this, 'update_seo_fields'],
            'permission_callback' => function () { return current_user_can('edit_posts'); },
            'args'                => [
                'post_type' => ['required' => true, 'sanitize_callback' => 'sanitize_key'],
                'id'        => ['required' => true, 'validate_callback' => function ($v) { return is_numeric($v); }],
            ],
        ]);
    }

    public function update_seo_fields(WP_REST_Request $request) {
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

        $yoast_map = [
            'title'       => '_yoast_wpseo_title',
            'description' => '_yoast_wpseo_metadesc',
            'focuskw'     => '_yoast_wpseo_focuskw',
        ];

        $updated = [];
        foreach ($yoast_map as $field => $meta_key) {
            if (array_key_exists($field, $body)) {
                $value = sanitize_text_field((string) $body[$field]);
                update_post_meta($post_id, $meta_key, $value);
                $updated[$field] = $value;
            }
        }

        if (empty($updated)) {
            return new WP_Error('no_fields',
                'No SEO fields provided. Send at least one of: title, description, focuskw.',
                ['status' => 400]
            );
        }

        return rest_ensure_response([
            'success'        => true,
            'id'             => $post_id,
            'post_type'      => $post_type,
            'title'          => get_the_title($post_id),
            'updated_fields' => $updated,
        ]);
    }
}
