<?php
if (!defined('ABSPATH')) exit;

class Evive_Preview_Token {

    public function register_routes() {
        register_rest_route('evive/v1', '/preview-token/(?P<post_type>[a-zA-Z0-9_-]+)/(?P<id>\d+)', [
            'methods'             => 'POST',
            'callback'            => [$this, 'generate_token'],
            'permission_callback' => function () { return current_user_can('edit_posts'); },
            'args'                => [
                'post_type' => ['required' => true, 'sanitize_callback' => 'sanitize_key'],
                'id'        => ['required' => true, 'validate_callback' => function ($v) { return is_numeric($v); }],
            ],
        ]);
    }

    public function generate_token(WP_REST_Request $request) {
        $post_type = $request->get_param('post_type');
        $post_id   = (int) $request->get_param('id');

        $post = get_post($post_id);
        if (!$post || $post->post_type !== $post_type) {
            return new WP_Error('not_found', 'Post not found', ['status' => 404]);
        }

        // Cryptographically random 32-character hex token
        $token   = bin2hex(random_bytes(16));
        $expires = time() + 3600;

        // One token per post — overwriting invalidates any previous token
        update_post_meta($post_id, '_evive_preview_token',         $token);
        update_post_meta($post_id, '_evive_preview_token_expires', $expires);

        $preview_url = add_query_arg([
            'evive_preview' => $token,
            'post_id'       => $post_id,
        ], get_site_url('/'));

        return rest_ensure_response([
            'preview_url' => $preview_url,
            'expires_at'  => gmdate('Y-m-d\TH:i:s\Z', $expires),
        ]);
    }

    /**
     * Hooked to pre_get_posts (priority 1) — modifies the main query so
     * WordPress fetches the correct post regardless of its status or slug.
     */
    public static function maybe_intercept_query(WP_Query $query) {
        if (is_admin() || !$query->is_main_query()) return;

        $token   = sanitize_text_field(wp_unslash($_GET['evive_preview'] ?? ''));
        $post_id = (int) ($_GET['post_id'] ?? 0);
        if (!$token || !$post_id) return;

        // Quick validation — if invalid we leave the query untouched so the
        // normal 404 fires, then template_redirect shows the styled error.
        $stored  = get_post_meta($post_id, '_evive_preview_token', true);
        $expires = (int) get_post_meta($post_id, '_evive_preview_token_expires', true);
        if (!$stored || !hash_equals((string) $stored, $token) || time() > $expires) return;

        // Rewrite the main query to fetch this specific post in any status.
        // IMPORTANT: also clear page_id / pagename / name — when the URL is
        // plain /? (no path), WP sets page_id to the static front-page ID.
        // WP_Query::get_posts() processes page_id AFTER p and overwrites p
        // with page_id, so clearing it is required or the homepage is served.
        $query->set('p',           $post_id);
        $query->set('page_id',     0);
        $query->set('pagename',    '');
        $query->set('name',        '');
        $query->set('post_type',   'any');
        $query->set('post_status', ['publish', 'draft', 'pending', 'private', 'future', 'inherit']);
        $query->is_singular   = true;
        $query->is_single     = true;
        $query->is_page       = false;
        $query->is_home       = false;
        $query->is_front_page = false;
        $query->is_archive    = false;
        $query->is_404        = false;
    }

    /**
     * Hooked to template_redirect (priority 1) — validates the token and
     * either serves the preview or shows an informative error. Also strips
     * X-Frame-Options so the page can be embedded in the evive iframe.
     */
    public static function maybe_serve_preview() {
        $token   = sanitize_text_field(wp_unslash($_GET['evive_preview'] ?? ''));
        $post_id = (int) ($_GET['post_id'] ?? 0);
        if (!$token || !$post_id) return;

        $stored  = get_post_meta($post_id, '_evive_preview_token', true);
        $expires = (int) get_post_meta($post_id, '_evive_preview_token_expires', true);

        if (!$stored || !hash_equals((string) $stored, $token)) {
            self::render_preview_error(
                'Preview link invalid',
                'This preview link is not valid. Please generate a new one from evive.'
            );
        }

        if (time() > $expires) {
            self::render_preview_error(
                'Preview link expired',
                'This preview link has expired (links are valid for 1 hour). Ask evive to generate a fresh preview.'
            );
        }

        // Valid token — allow iframe embedding by overriding plugin/WP headers.
        // Server-level headers (nginx) take precedence; this covers PHP-level ones.
        if (!headers_sent()) {
            header_remove('X-Frame-Options');
            header('X-Content-Type-Options: nosniff');
        }

        // Preview banner injected before </body> — non-intrusive debug strip
        add_action('wp_footer', function () use ($post_id, $expires) {
            $exp_local = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $expires);
            echo '<div style="position:fixed;bottom:0;left:0;right:0;z-index:99999;'
               . 'background:#1a3a2a;color:#c8f535;font-family:sans-serif;font-size:12px;'
               . 'padding:6px 12px;display:flex;gap:12px;align-items:center">'
               . '<strong>evive preview</strong>'
               . '<span style="color:#fff;opacity:.7">Draft · expires ' . esc_html($exp_local) . '</span>'
               . '</div>';
        }, 999);
    }

    private static function render_preview_error(string $title, string $message): void {
        status_header(410);
        nocache_headers();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html($title); ?></title>
<style>
  body{margin:0;display:flex;align-items:center;justify-content:center;min-height:100vh;
    background:#f5f0e8;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#1a1a18}
  .card{background:#fff;border-radius:12px;padding:2.5rem 2rem;max-width:380px;width:100%;
    box-shadow:0 4px 24px rgba(0,0,0,.1);text-align:center}
  .icon{font-size:2.5rem;margin-bottom:.75rem}
  h1{font-size:1.1rem;font-weight:600;color:#1a3a2a;margin:0 0 .5rem}
  p{font-size:.875rem;color:#6a6a60;line-height:1.55;margin:0 0 1.5rem}
  a{display:inline-block;padding:.5rem 1.25rem;background:#1a3a2a;color:#c8f535;
    border-radius:8px;font-size:.875rem;font-weight:500;text-decoration:none}
</style>
</head>
<body>
<div class="card">
  <div class="icon">🔗</div>
  <h1><?php echo esc_html($title); ?></h1>
  <p><?php echo esc_html($message); ?></p>
  <a href="<?php echo esc_url(home_url('/')); ?>">← Back to site</a>
</div>
</body>
</html>
        <?php
        exit;
    }
}

// Register hooks outside class instantiation so they fire on all requests
add_action('pre_get_posts',    ['Evive_Preview_Token', 'maybe_intercept_query'], 1);
add_action('template_redirect',['Evive_Preview_Token', 'maybe_serve_preview'],  1);
add_action('init', function () {
    $auth = function () { return current_user_can('edit_posts'); };
    foreach (get_post_types(['public' => true]) as $pt) {
        register_post_meta($pt, '_evive_preview_token', [
            'show_in_rest'  => true, 'single' => true,
            'type'          => 'string',
            'auth_callback' => $auth,
        ]);
        register_post_meta($pt, '_evive_preview_token_expires', [
            'show_in_rest'  => true, 'single' => true,
            'type'          => 'integer',
            'auth_callback' => $auth,
        ]);
    }
});
