<?php
if (!defined('ABSPATH')) exit;

/**
 * Paged, read-only list of the items of one post type, including post types that are not
 * available through the WordPress REST API (e.g. a theme's portfolio). The site profile lists
 * only the newest items of long custom post types; evive's wp_list_items tool reaches the rest
 * here when /wp/v2/types has no rest_base for the type (evive #385, connector 1.3.27).
 */
class Evive_Items {

    public function register_routes() {
        register_rest_route('evive/v1', '/items', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_items'],
            'permission_callback' => function () { return current_user_can('edit_posts'); },
            'args'                => [
                'post_type' => ['required' => true, 'sanitize_callback' => 'sanitize_key'],
                'page'      => ['default' => 1,  'sanitize_callback' => 'absint'],
                'per_page'  => ['default' => 50, 'sanitize_callback' => 'absint'],
                'search'    => ['default' => '', 'sanitize_callback' => 'sanitize_text_field'],
                'status'    => ['default' => 'any', 'sanitize_callback' => 'sanitize_key'],
            ],
        ]);
    }

    public function list_items(WP_REST_Request $request) {
        $post_type = $request->get_param('post_type');
        if (!in_array($post_type, get_post_types(), true)) {
            return new WP_Error('unknown_post_type', "Post type \"{$post_type}\" does not exist on this site.", ['status' => 404]);
        }
        // Registered is not enough: internal types (privacy requests, changesets) must stay out of
        // reach for users who could not see them in wp-admin either.
        $type_object = get_post_type_object($post_type);
        if (!$type_object || !current_user_can($type_object->cap->edit_posts)) {
            return new WP_Error('forbidden_post_type', "You may not list items of post type \"{$post_type}\".", ['status' => 403]);
        }

        $status = $request->get_param('status');
        if (!in_array($status, ['publish', 'draft', 'any'], true)) {
            return new WP_Error('invalid_status', 'status must be publish, draft or any.', ['status' => 400]);
        }
        $page     = max(1, (int) $request->get_param('page'));
        $per_page = min(100, max(1, (int) $request->get_param('per_page')));
        $search   = (string) $request->get_param('search');

        $args = [
            'post_type'           => $post_type,
            'post_status'         => $status,
            'posts_per_page'      => $per_page,
            'paged'               => $page,
            'orderby'             => 'date',
            'order'               => 'DESC',
            'ignore_sticky_posts' => true,
            'no_found_rows'       => false,
            'lang'                => '', // Polylang: all languages, not only the current one
        ];
        if ($search !== '') $args['s'] = $search;

        $query = new WP_Query($args);
        $items = [];
        foreach ($query->posts as $p) {
            $items[] = [
                'id'     => $p->ID,
                'title'  => html_entity_decode(get_the_title($p), ENT_QUOTES, 'UTF-8'),
                'slug'   => $p->post_name,
                'status' => $p->post_status,
                'date'   => mysql2date('Y-m-d', $p->post_date),
            ];
        }
        $total_pages = (int) $query->max_num_pages;

        return rest_ensure_response([
            'items'       => $items,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => $total_pages,
            'next_page'   => $page < $total_pages ? $page + 1 : null,
        ]);
    }
}
