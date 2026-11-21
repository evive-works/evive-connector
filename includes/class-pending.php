<?php
if (!defined('ABSPATH')) exit;

/**
 * Pending changes for published pages (evive #539, design evive #538, D1-D6).
 *
 * A published block page is never tweaked in place. "Start" makes one linked draft copy (the pending copy) on the same
 * site: same content, title, parent, template, featured image and Polylang language; no slug, no translation link, no
 * SEO or _evive_* meta. Everything that works on drafts (tweak mode, evive_apply_tweak, preview tokens) works on it.
 *   live post: _evive_pending_id    = the copy's ID
 *   copy:      _evive_pending_of    = the live post's ID
 *              _evive_pending_base  = sha256 of the live post_content + post_modified_gmt when the copy was made
 * Publish puts only the copy's post_content on the live post (title, slug, SEO, featured image stay), after a revision
 * of the live state; a live post that changed since the copy was made gives 409 evive_pending_conflict unless force.
 * Discard trashes the copy. Both remove the meta on both posts.
 *
 * Routes (evive/v1, edit_post on the live post): POST /pending/{type}/{id} start or return the copy, GET status,
 * POST .../publish {force?}, POST .../discard, GET /pending (all pending copies on this site).
 * Multisite: everything happens on the site the request goes to (the live post's own language site); meta is per blog.
 */
class Evive_Pending {

    const LIVE_META = '_evive_pending_id';
    const OF_META   = '_evive_pending_of';
    const BASE_META = '_evive_pending_base';

    /** @var bool True while this class itself writes (publish/discard), so the copy guard stays out of the way. */
    private static $internal = false;

    public static function init() {
        add_filter('wp_insert_post_data', [__CLASS__, 'guard_copy_publish'], 99, 2);
        add_filter('display_post_states', [__CLASS__, 'post_states'], 10, 2);
        add_action('admin_notices', [__CLASS__, 'admin_notice']);
    }

    public function register_routes() {
        $args = [
            'post_type' => ['required' => true, 'sanitize_callback' => 'sanitize_key'],
            'id'        => ['required' => true, 'validate_callback' => function ($v) { return is_numeric($v); }],
        ];
        $base = '/pending/(?P<post_type>[a-zA-Z0-9_-]+)/(?P<id>\d+)';
        register_rest_route('evive/v1', $base, [
            ['methods' => 'GET',  'callback' => [$this, 'status'], 'permission_callback' => [$this, 'can_edit_live'], 'args' => $args],
            ['methods' => 'POST', 'callback' => [$this, 'start'],  'permission_callback' => [$this, 'can_edit_live'], 'args' => $args],
        ]);
        register_rest_route('evive/v1', $base . '/publish', [
            'methods' => 'POST', 'callback' => [$this, 'publish'], 'permission_callback' => [$this, 'can_edit_live'], 'args' => $args,
        ]);
        register_rest_route('evive/v1', $base . '/discard', [
            'methods' => 'POST', 'callback' => [$this, 'discard'], 'permission_callback' => [$this, 'can_edit_live'], 'args' => $args,
        ]);
        register_rest_route('evive/v1', '/pending', [
            'methods' => 'GET', 'callback' => [$this, 'list_all'],
            'permission_callback' => function () { return current_user_can('edit_posts'); },
        ]);
    }

    /** edit_post on the live post (the {id} of the route). A missing post is answered by the callback (404). */
    public function can_edit_live(WP_REST_Request $request) {
        $id = (int) $request->get_param('id');
        if (!get_post($id)) return current_user_can('edit_posts');
        if (current_user_can('edit_post', $id)) return true;
        return new WP_Error('evive_forbidden', 'evive needs edit rights on this page for its pending changes.', ['status' => rest_authorization_required_code()]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** The base of a live post: sha256 of its post_content and post_modified_gmt. */
    public static function base_of(WP_Post $live) {
        return hash('sha256', $live->post_content . "\n" . $live->post_modified_gmt);
    }

    /** The live post ID a copy belongs to, or 0 when $post_id is not a pending copy. */
    public static function live_of($post_id) {
        return (int) get_post_meta((int) $post_id, self::OF_META, true);
    }

    /** The valid pending copy of a live post, or null (a stale pointer, e.g. a copy trashed in wp-admin, is cleared). */
    public static function copy_of($live_id) {
        $copy_id = (int) get_post_meta($live_id, self::LIVE_META, true);
        if (!$copy_id) return null;
        $copy = get_post($copy_id);
        if ($copy && $copy->post_status === 'draft' && self::live_of($copy_id) === (int) $live_id) return $copy;
        delete_post_meta($live_id, self::LIVE_META);
        return null;
    }

    private function live_post(WP_REST_Request $request) {
        $post = get_post((int) $request->get_param('id'));
        if (!$post || $post->post_type !== $request->get_param('post_type')) {
            return new WP_Error('not_found', 'Post not found', ['status' => 404]);
        }
        if (self::live_of($post->ID)) {
            return new WP_Error('evive_pending_is_copy',
                'Post ' . $post->ID . ' is itself a pending copy (of post ' . self::live_of($post->ID) . '); use the live post ID.',
                ['status' => 400, 'live_id' => self::live_of($post->ID)]);
        }
        return $post;
    }

    /** A post's last change as RFC 3339 GMT. Drafts keep post_modified_gmt at 0000-00-00 until a later save in some
     *  paths, so then the local post_modified is converted. */
    private static function modified_gmt(WP_Post $post) {
        $gmt = $post->post_modified_gmt;
        if (!$gmt || strpos($gmt, '0000-00-00') === 0) $gmt = get_gmt_from_date($post->post_modified);
        return mysql_to_rfc3339($gmt) ?: $gmt;
    }

    /** Who last changed a post and when (for the conflict answer and the status). */
    private static function last_change(WP_Post $post) {
        $uid  = (int) get_post_meta($post->ID, '_edit_last', true);
        if (!$uid && wp_revisions_enabled($post)) {
            // REST and wp_update_post saves do not set _edit_last; the latest revision's author is who saved last.
            $latest = wp_get_post_revisions($post->ID, ['posts_per_page' => 1, 'orderby' => 'date ID', 'order' => 'DESC']);
            $latest = $latest ? reset($latest) : null;
            if ($latest) $uid = (int) $latest->post_author;
        }
        $user = $uid ? get_userdata($uid) : null;
        return [
            'modified_gmt' => self::modified_gmt($post),
            'modified_by'  => $user ? $user->display_name : null,
            'modified_by_id' => $uid ?: null,
        ];
    }

    private static function describe(WP_Post $live, $copy) {
        $out = [
            'live_id'     => $live->ID,
            'post_type'   => $live->post_type,
            'live_url'    => get_permalink($live),
            'live_status' => $live->post_status,
            'title'       => get_the_title($live),
            'pending'     => (bool) $copy,
            'copy_id'     => $copy ? $copy->ID : null,
        ];
        if ($copy) {
            $base = (string) get_post_meta($copy->ID, self::BASE_META, true);
            $out['copy_modified_gmt'] = self::modified_gmt($copy);
            $out['copy_edit_url']     = get_edit_post_link($copy->ID, 'raw');
            $out['live_changed']      = !hash_equals($base, self::base_of($live)); // publish would give 409
            $out['live_last_change']  = self::last_change($live);
        }
        return $out;
    }

    // ── Routes ──────────────────────────────────────────────────────────────

    public function status(WP_REST_Request $request) {
        $live = $this->live_post($request);
        if (is_wp_error($live)) return $live;
        return rest_ensure_response(self::describe($live, self::copy_of($live->ID)));
    }

    /** Start: make the copy, or return the one that is there (start twice = same copy). */
    public function start(WP_REST_Request $request) {
        $live = $this->live_post($request);
        if (is_wp_error($live)) return $live;

        $copy = self::copy_of($live->ID);
        if ($copy) return rest_ensure_response(self::describe($live, $copy) + ['created' => false]);

        if ($live->post_status !== 'publish') {
            return new WP_Error('evive_pending_not_published',
                'Pending changes are only for published pages; this one is "' . $live->post_status . '": tweak it directly.',
                ['status' => 400]);
        }
        if (!function_exists('has_blocks') || !has_blocks($live->post_content)) {
            return new WP_Error('evive_pending_no_blocks', 'Pending changes need a block page (no block content found).', ['status' => 400]);
        }

        // D1: a draft with the same content, title, parent, template and featured image; no slug (drafts get none),
        // no meta except our own. wp_slash: wp_insert_post unslashes its input.
        $copy_id = wp_insert_post(wp_slash([
            'post_type'      => $live->post_type,
            'post_status'    => 'draft',
            'post_title'     => $live->post_title,
            'post_content'   => $live->post_content,
            'post_excerpt'   => $live->post_excerpt,
            'post_parent'    => $live->post_parent,
            'menu_order'     => $live->menu_order,
            'post_author'    => get_current_user_id() ?: $live->post_author,
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ]), true);
        if (is_wp_error($copy_id)) {
            return new WP_Error('evive_pending_copy_failed', 'Could not make the pending copy: ' . $copy_id->get_error_message(), ['status' => 500]);
        }

        $template = get_post_meta($live->ID, '_wp_page_template', true);
        if ($template) update_post_meta($copy_id, '_wp_page_template', $template);
        $thumb = get_post_thumbnail_id($live->ID);
        if ($thumb) set_post_thumbnail($copy_id, $thumb);
        // Polylang: the same language, but no translation link (the evive-language links stay on the live post).
        if (function_exists('pll_get_post_language') && function_exists('pll_set_post_language')) {
            $lang = pll_get_post_language($live->ID);
            if ($lang) pll_set_post_language($copy_id, $lang);
        }

        update_post_meta($copy_id, self::OF_META, $live->ID);
        update_post_meta($copy_id, self::BASE_META, self::base_of($live));
        update_post_meta($live->ID, self::LIVE_META, $copy_id);

        return rest_ensure_response(self::describe($live, get_post($copy_id)) + ['created' => true]);
    }

    /** Publish (D5): only the copy's post_content goes live. D2: 409 when the live post changed, unless force. */
    public function publish(WP_REST_Request $request) {
        $live = $this->live_post($request);
        if (is_wp_error($live)) return $live;
        $copy = self::copy_of($live->ID);
        if (!$copy) return new WP_Error('evive_pending_none', 'This page has no pending changes.', ['status' => 404]);
        if ($live->post_status !== 'publish') {
            return new WP_Error('evive_pending_not_published', 'The live page is not published any more ("' . $live->post_status . '").', ['status' => 409]);
        }

        $force   = rest_sanitize_boolean($request->get_param('force'));
        $base    = (string) get_post_meta($copy->ID, self::BASE_META, true);
        $changed = !hash_equals($base, self::base_of($live));
        if ($changed && !$force) {
            $who = self::last_change($live);
            return new WP_Error('evive_pending_conflict',
                'The live page was changed after the pending changes were started (' . ($who['modified_by'] ?: 'unknown user') . ', '
                . $who['modified_gmt'] . '). Publish anyway (force: the live change is kept as a revision) or discard and start again.',
                ['status' => 409, 'live_last_change' => $who, 'modified_by' => $who['modified_by'], 'modified_at' => $who['modified_gmt'],
                 'live_id' => $live->ID, 'copy_id' => $copy->ID]); // modified_by/_at: the names the MCP (#540) reads
        }

        // A revision of the live state as it is now, to go back to. wp_save_post_revision() saves nothing when the
        // latest revision already equals the post; then that latest revision is the one.
        $restore_id = 0;
        if (wp_revisions_enabled($live)) {
            $rev = wp_save_post_revision($live->ID);
            if ($rev && !is_wp_error($rev)) {
                $restore_id = (int) $rev;
            } else {
                $latest = wp_get_post_revisions($live->ID, ['posts_per_page' => 1, 'orderby' => 'date ID', 'order' => 'DESC']);
                $latest = $latest ? reset($latest) : null;
                if ($latest && $latest->post_content === $live->post_content) $restore_id = (int) $latest->ID;
            }
        }

        self::$internal = true;
        $res = wp_update_post(wp_slash(['ID' => $live->ID, 'post_content' => $copy->post_content]), true);
        self::$internal = false;
        if (is_wp_error($res)) {
            return new WP_Error('evive_pending_publish_failed', 'Could not publish the pending changes: ' . $res->get_error_message(), ['status' => 500]);
        }
        evive_purge_post_cache($live->ID);

        $this->drop_copy($live->ID, $copy->ID);
        $after = get_post($live->ID);
        return rest_ensure_response([
            'published'           => true,
            'live_id'             => $live->ID,
            'live_url'            => get_permalink($after),
            'forced'              => $changed && $force,
            'restore_revision_id' => $restore_id ?: null,
            'copy_id'             => $copy->ID,
            'copy_status'         => get_post_status($copy->ID),
        ]);
    }

    /** Discard (D6): the copy to the trash, the meta removed on both. The live post is not touched. */
    public function discard(WP_REST_Request $request) {
        $live = $this->live_post($request);
        if (is_wp_error($live)) return $live;
        $copy = self::copy_of($live->ID);
        if (!$copy) return new WP_Error('evive_pending_none', 'This page has no pending changes.', ['status' => 404]);
        $this->drop_copy($live->ID, $copy->ID);
        return rest_ensure_response([
            'discarded'   => true,
            'live_id'     => $live->ID,
            'live_url'    => get_permalink($live),
            'copy_id'     => $copy->ID,
            'copy_status' => get_post_status($copy->ID),
        ]);
    }

    /** All pending copies on this site that the user can edit. */
    public function list_all(WP_REST_Request $request) {
        $ids = get_posts([
            'post_type'      => array_values(get_post_types()), // 'any' skips types excluded from search
            'post_status'    => 'draft',
            'meta_key'       => self::OF_META,
            'posts_per_page' => 200,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);
        $out = [];
        foreach ($ids as $copy_id) {
            $live = get_post(self::live_of($copy_id));
            if (!$live || !current_user_can('edit_post', $live->ID)) continue;
            $copy = self::copy_of($live->ID);
            if (!$copy || (int) $copy->ID !== (int) $copy_id) continue; // an orphan copy (the live post points elsewhere)
            $out[] = self::describe($live, $copy);
        }
        return rest_ensure_response(['pending' => $out, 'count' => count($out)]);
    }

    private function drop_copy($live_id, $copy_id) {
        delete_post_meta($live_id, self::LIVE_META);
        delete_post_meta($copy_id, self::OF_META);
        delete_post_meta($copy_id, self::BASE_META);
        self::$internal = true;
        wp_trash_post($copy_id);
        self::$internal = false;
    }

    // ── wp-admin ────────────────────────────────────────────────────────────

    /** D4: the copy's own Publish (block editor, classic editor, REST) keeps it a draft without a slug. */
    public static function guard_copy_publish($data, $postarr) {
        if (self::$internal || empty($postarr['ID'])) return $data;
        if (!in_array($data['post_status'] ?? '', ['publish', 'future', 'private'], true)) return $data;
        $live_id = self::live_of((int) $postarr['ID']);
        if (!$live_id) return $data;
        $data['post_status'] = 'draft';
        $data['post_name']   = '';
        set_transient('evive_pending_blocked_' . get_current_user_id(), (int) $postarr['ID'], 60);
        return $data;
    }

    public static function post_states($states, $post) {
        if (self::live_of($post->ID)) $states['evive_pending'] = 'evive: pending changes';
        elseif (get_post_meta($post->ID, self::LIVE_META, true)) $states['evive_pending_live'] = 'evive: changes not live yet';
        return $states;
    }

    /** Classic screens: say why the copy is still a draft. (The block editor shows its own notice, tweak-editor.js.) */
    public static function admin_notice() {
        $key = 'evive_pending_blocked_' . get_current_user_id();
        $id  = (int) get_transient($key);
        if (!$id) return;
        delete_transient($key);
        echo '<div class="notice notice-warning"><p>' . esc_html(self::copy_notice($id)) . '</p></div>';
    }

    public static function copy_notice($copy_id) {
        $live = get_post(self::live_of($copy_id));
        return 'These are evive pending changes of "' . ($live ? get_the_title($live) : 'a live page')
            . '". They stay a draft here: publish them from evive (Publish changes), so they replace the live page.';
    }

    /** Data for the block editor (tweak-editor.js): is the edited post a copy, or a live post with a copy? */
    public static function editor_data($post_id) {
        $post_id = (int) $post_id;
        if (!$post_id) return null;
        if (self::live_of($post_id)) {
            return ['role' => 'copy', 'message' => self::copy_notice($post_id)];
        }
        $live = get_post($post_id);
        if ($live && self::copy_of($post_id)) {
            return ['role' => 'live', 'message' => 'This page has evive changes that are not live yet. Edits you make here '
                . 'can conflict with them: evive will ask to publish anyway or discard its changes.'];
        }
        return null;
    }
}

Evive_Pending::init();
