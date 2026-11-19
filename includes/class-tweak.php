<?php
if (!defined('ABSPATH')) exit;

/**
 * Tweak mode (evive #522, design evive #520).
 *
 * 1. Block support attribute `eviveTweak` on every block:
 *      {"desktop"|"tablet"|"phone": {"x": int, "y": int, "width": int, "hidden": bool}}
 *    x / y: move in steps of --evive-tweak-step (the theme's spacing preset 20), -40..40.
 *    width: % of the parent, 10..100 in steps of 5 (images keep their proportions).
 *    hidden: not shown on that screen size.
 *    Each screen size has its own range (phone <= 600px, tablet 601-1024px, desktop >= 1025px), so a tweak on one size
 *    never changes another. Rendered as scoped CSS on a class (evive-tw-<hash>); the saved block HTML is not changed,
 *    so the block editor never reports "unexpected content".
 * 2. Tweak mode of the draft preview: ?evive_preview=<preview token>&post_id=<id>&evive_tweak=<tweak token>.
 *    The tweak token comes from the evive app (POST /evive/v1/preview-token/... with tweak_token, tweak_endpoint).
 *    The page then shows a toolbar (Desktop/Tablet/Phone) around an iframe of the same preview with
 *    evive_tweak_frame=1, in which every block of the post content has a block path (data-evive-path, e.g. "2.0.1":
 *    indices of real blocks, skipping whitespace, from the top of the post content) and an overlay script.
 *    The overlay sends the tweaks to the evive app (tweak_endpoint); it never writes to WordPress itself.
 */
class Evive_Tweak {

    const ATTR      = 'eviveTweak';
    const SIZES     = ['desktop', 'tablet', 'phone'];
    const TOKEN_TTL = 1800; // 30 minutes

    /** @var bool  True while serving the tweak frame (block paths and overlay). */
    private static $frame = false;
    /** @var int   The post of the tweak frame. */
    private static $post_id = 0;
    /** @var array CSS rules by class name, not printed yet. */
    private static $pending = [];
    /** @var array Class names already printed. */
    private static $printed = [];
    private static $root_printed = false;

    public static function init() {
        add_filter('register_block_type_args', [__CLASS__, 'add_attribute'], 10, 2);
        add_filter('render_block', [__CLASS__, 'render_block'], 10, 2);
        add_filter('the_content', [__CLASS__, 'annotate_paths'], 8);
        add_action('template_redirect', [__CLASS__, 'maybe_serve'], 2);
        add_action('wp_head', [__CLASS__, 'print_css'], 99);
        add_action('wp_footer', [__CLASS__, 'print_css'], 99);
        add_action('enqueue_block_editor_assets', [__CLASS__, 'editor_assets']);
    }

    // ── Breakpoints ─────────────────────────────────────────────────────────

    /** Media queries per screen size. Filter: evive_tweak_media (array size => media query). */
    public static function media() {
        return apply_filters('evive_tweak_media', [
            'desktop' => '(min-width: 1025px)',
            'tablet'  => '(min-width: 601px) and (max-width: 1024px)',
            'phone'   => '(max-width: 600px)',
        ]);
    }

    /** Size of one move step. Filter: evive_tweak_step (a CSS length, presets preferred). */
    public static function step() {
        return apply_filters('evive_tweak_step', 'var(--wp--preset--spacing--20, 0.5rem)');
    }

    // ── The attribute ───────────────────────────────────────────────────────

    /** Server side: every block type knows eviveTweak (also needed by server-side-rendered blocks in the editor). */
    public static function add_attribute($args, $name) {
        if (!isset($args['attributes']) || !is_array($args['attributes'])) $args['attributes'] = [];
        if (!isset($args['attributes'][self::ATTR])) $args['attributes'][self::ATTR] = ['type' => 'object'];
        return $args;
    }

    /**
     * Clean a tweak value: only known sizes and keys, integers in range, width snapped to 5 %.
     * Returns [] when nothing is left.
     */
    public static function sanitize($value) {
        if (!is_array($value)) return [];
        $out = [];
        foreach (self::SIZES as $size) {
            $t = $value[$size] ?? null;
            if (!is_array($t)) continue;
            $c = [];
            foreach (['x', 'y'] as $k) {
                if (isset($t[$k]) && is_numeric($t[$k])) {
                    $v = max(-40, min(40, (int) round($t[$k])));
                    if ($v !== 0) $c[$k] = $v;
                }
            }
            if (isset($t['width']) && is_numeric($t['width'])) {
                $w = (int) (round($t['width'] / 5) * 5);
                $w = max(10, min(100, $w));
                $c['width'] = $w;
            }
            if (!empty($t['hidden'])) $c['hidden'] = true;
            if ($c) $out[$size] = $c;
        }
        return $out;
    }

    /** The scoped CSS for one tweak on class $cls. $is_image: also scale the <img> inside. */
    public static function css($cls, array $tweak, $is_image) {
        $sel   = '.' . $cls . '.' . $cls; // two classes: wins over layout styles without !important
        $media = self::media();
        $css   = '';
        foreach (self::SIZES as $size) {
            if (empty($tweak[$size]) || empty($media[$size])) continue;
            $t     = $tweak[$size];
            $rules = [];
            if (!empty($t['hidden'])) {
                $rules[] = 'display:none';
            } else {
                if (!empty($t['x']) || !empty($t['y'])) {
                    $rules[] = sprintf('translate:calc(%d * var(--evive-tweak-step)) calc(%d * var(--evive-tweak-step))',
                        (int) ($t['x'] ?? 0), (int) ($t['y'] ?? 0));
                }
                if (!empty($t['width'])) {
                    $rules[] = sprintf('width:%d%%', (int) $t['width']);
                    $rules[] = sprintf('max-width:%d%%', (int) $t['width']);
                    $rules[] = 'box-sizing:border-box';
                }
            }
            if (!$rules) continue;
            $css .= '@media ' . $media[$size] . '{' . $sel . '{' . implode(';', $rules) . '}';
            if ($is_image && !empty($t['width']) && empty($t['hidden'])) {
                // Images keep their proportions: the image fills the new width, its height follows.
                $css .= $sel . ' img{width:100% !important;height:auto !important}';
            }
            $css .= '}';
        }
        return $css;
    }

    // ── Rendering ───────────────────────────────────────────────────────────

    public static function render_block($content, $block) {
        $attrs = $block['attrs'] ?? [];
        $path  = self::$frame ? ($attrs['__evivePath'] ?? null) : null;
        $tweak = isset($attrs[self::ATTR]) ? self::sanitize($attrs[self::ATTR]) : [];
        if (!$tweak && $path === null) return $content;
        if (!is_string($content) || trim($content) === '') return $content;

        $set = [];
        $add_class = '';
        if ($tweak) {
            $json      = wp_json_encode($tweak);
            $add_class = 'evive-tw-' . substr(md5($json), 0, 10);
            $is_image  = in_array($block['blockName'] ?? '', ['core/image', 'core/post-featured-image', 'core/site-logo'], true);
            if (!isset(self::$printed[$add_class]) && !isset(self::$pending[$add_class])) {
                self::$pending[$add_class] = self::css($add_class, $tweak, $is_image);
            }
            if (self::$frame) $set['data-evive-tweak'] = $json;
        }
        if ($path !== null) {
            $set['data-evive-path']  = (string) $path;
            $set['data-evive-block'] = (string) ($block['blockName'] ?? '');
            if (!$tweak) $set['data-evive-tweak'] = '{}';
        }
        return self::add_to_first_tag($content, $add_class, $set);
    }

    /** Add a class and attributes to the first HTML tag of a block's output. */
    private static function add_to_first_tag($html, $class, array $set) {
        if (class_exists('WP_HTML_Tag_Processor')) {
            $p = new WP_HTML_Tag_Processor($html);
            if (!$p->next_tag()) return $html;
            if ($class) $p->add_class($class);
            foreach ($set as $k => $v) $p->set_attribute($k, $v);
            return $p->get_updated_html();
        }
        // WordPress < 6.2: first opening tag by pattern.
        if (!preg_match('/^(\s*<[a-zA-Z][a-zA-Z0-9-]*)(\s[^>]*)?(\/?>)/', $html, $m)) return $html;
        $attrs = $m[2] ?? '';
        if ($class) {
            if (preg_match('/\sclass=("|\')(.*?)\1/', $attrs, $cm)) {
                $attrs = str_replace($cm[0], ' class=' . $cm[1] . $cm[2] . ' ' . esc_attr($class) . $cm[1], $attrs);
            } else {
                $attrs .= ' class="' . esc_attr($class) . '"';
            }
        }
        foreach ($set as $k => $v) $attrs .= ' ' . $k . '="' . esc_attr($v) . '"';
        return $m[1] . $attrs . $m[3] . substr($html, strlen($m[0]));
    }

    /** Tweak frame only: give every block of the post content its path (as a temporary attribute, never saved). */
    public static function annotate_paths($content) {
        if (!self::$frame || (int) get_the_ID() !== self::$post_id) return $content;
        if (!function_exists('has_blocks') || !has_blocks($content)) return $content;
        $blocks = parse_blocks($content);
        self::walk($blocks, '');
        return serialize_blocks($blocks);
    }

    private static function walk(array &$blocks, $prefix) {
        $i = 0;
        foreach ($blocks as &$b) {
            if (empty($b['blockName'])) continue; // whitespace / freeform between blocks has no path
            $path = $prefix === '' ? (string) $i : $prefix . '.' . $i;
            if (!is_array($b['attrs'] ?? null)) $b['attrs'] = [];
            $b['attrs']['__evivePath'] = $path;
            if (!empty($b['innerBlocks'])) self::walk($b['innerBlocks'], $path);
            $i++;
        }
        unset($b);
    }

    public static function print_css() {
        $css = '';
        if (!self::$root_printed && (self::$pending || self::$frame)) {
            $css .= ':root{--evive-tweak-step:' . self::step() . '}';
            self::$root_printed = true;
        }
        foreach (self::$pending as $cls => $rules) {
            $css .= $rules;
            self::$printed[$cls] = true;
        }
        self::$pending = [];
        if ($css === '') return;
        // The rules are built from sanitized integers only (no user CSS).
        echo '<style id="evive-tweak-css-' . (did_action('wp_footer') ? 'footer' : 'head') . '">' . $css . "</style>\n";
    }

    // ── Tweak tokens ────────────────────────────────────────────────────────

    /**
     * Store a tweak token from the evive app for a post (called by the preview-token route).
     * Returns [token expiry, error message or ''].
     */
    public static function store_token($post_id, $token, $endpoint) {
        $token = (string) $token;
        if (!preg_match('/^[A-Za-z0-9_-]{32,128}$/', $token)) return [0, 'tweak_token must be 32-128 characters A-Z a-z 0-9 _ -'];
        $endpoint = trim((string) $endpoint);
        if ($endpoint !== '' && !self::endpoint_ok($endpoint)) return [0, 'tweak_endpoint must be an https URL'];
        $expires = time() + self::TOKEN_TTL;
        update_post_meta($post_id, '_evive_tweak_token_hash', hash('sha256', $token));
        update_post_meta($post_id, '_evive_tweak_token_expires', $expires);
        update_post_meta($post_id, '_evive_tweak_endpoint', esc_url_raw($endpoint));
        return [$expires, ''];
    }

    /** https only; http only for localhost (development). */
    private static function endpoint_ok($url) {
        $p = wp_parse_url($url);
        if (!$p || empty($p['host'])) return false;
        if (($p['scheme'] ?? '') === 'https') return true;
        return ($p['scheme'] ?? '') === 'http' && in_array($p['host'], ['localhost', '127.0.0.1'], true);
    }

    private static function token_valid($post_id, $token) {
        if (!preg_match('/^[A-Za-z0-9_-]{32,128}$/', (string) $token)) return false;
        $hash    = (string) get_post_meta($post_id, '_evive_tweak_token_hash', true);
        $expires = (int) get_post_meta($post_id, '_evive_tweak_token_expires', true);
        return $hash !== '' && hash_equals($hash, hash('sha256', $token)) && time() <= $expires;
    }

    /** True while the tweak frame is served (the preview banner stays out of it). */
    public static function is_frame() {
        return self::$frame;
    }

    // ── Tweak mode pages ────────────────────────────────────────────────────

    /** template_redirect, after Evive_Preview_Token::maybe_serve_preview (priority 1) checked the preview token. */
    public static function maybe_serve() {
        $tweak   = sanitize_text_field(wp_unslash($_GET['evive_tweak'] ?? ''));
        $preview = sanitize_text_field(wp_unslash($_GET['evive_preview'] ?? ''));
        $post_id = (int) ($_GET['post_id'] ?? 0);
        if ($tweak === '' || $preview === '' || !$post_id) return;

        if (!self::token_valid($post_id, $tweak)) {
            Evive_Preview_Token::render_preview_error(
                'Tweak link invalid or expired',
                'This tweak link is not valid any more (links are valid for 30 minutes). Open tweak mode again from evive.'
            );
        }
        nocache_headers();

        if (!empty($_GET['evive_tweak_frame'])) {
            self::$frame   = true;
            self::$post_id = $post_id;
            show_admin_bar(false);
            add_action('wp_enqueue_scripts', function () {
                wp_enqueue_style('evive-tweak-frame', plugins_url('assets/tweak-frame.css', EVIVE_PATH . 'evive-connector.php'), [], EVIVE_VERSION);
                wp_enqueue_script('evive-tweak-frame', plugins_url('assets/tweak-frame.js', EVIVE_PATH . 'evive-connector.php'), [], EVIVE_VERSION, true);
                wp_localize_script('evive-tweak-frame', 'eviveTweakFrame', ['media' => self::media()]);
            });
            return;
        }
        self::render_shell($post_id, $tweak, $preview);
    }

    private static function render_shell($post_id, $tweak, $preview) {
        $frame_url = add_query_arg([
            'evive_preview'     => $preview,
            'post_id'           => $post_id,
            'evive_tweak'       => $tweak,
            'evive_tweak_frame' => 1,
        ], home_url('/'));
        $config = [
            'post_id'  => $post_id,
            'site'     => home_url('/'),
            'title'    => get_the_title($post_id),
            'endpoint' => (string) get_post_meta($post_id, '_evive_tweak_endpoint', true),
            'token'    => $tweak,
            'expires'  => (int) get_post_meta($post_id, '_evive_tweak_token_expires', true),
            'widths'   => apply_filters('evive_tweak_widths', ['desktop' => 1280, 'tablet' => 820, 'phone' => 390]),
        ];
        $base = plugins_url('assets/', EVIVE_PATH . 'evive-connector.php');
        status_header(200);
        header('Content-Type: text/html; charset=utf-8');
        header('Referrer-Policy: no-referrer'); // the URL holds the tokens
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Tweak: <?php echo esc_html(get_the_title($post_id)); ?></title>
<link rel="stylesheet" href="<?php echo esc_url($base . 'tweak-shell.css?ver=' . EVIVE_VERSION); ?>">
</head>
<body>
<div id="evt-bar" role="toolbar" aria-label="evive tweak">
  <div class="evt-group" id="evt-devices">
    <button type="button" data-device="desktop" aria-pressed="true">Desktop</button>
    <button type="button" data-device="tablet" aria-pressed="false">Tablet</button>
    <button type="button" data-device="phone" aria-pressed="false">Phone</button>
  </div>
  <div class="evt-group evt-sel" id="evt-sel">Click a block to select it</div>
  <div class="evt-group" id="evt-tools">
    <button type="button" data-act="parent" title="Select the parent block (click the block again)">Parent</button>
    <button type="button" data-act="smaller" title="Smaller (5 %)">&minus;</button>
    <button type="button" data-act="bigger" title="Bigger (5 %)">+</button>
    <button type="button" data-act="hidephone" aria-pressed="false" title="Hide this block on phones">Hide on phone</button>
    <button type="button" data-act="reset" title="Undo the tweaks of this block on this screen size">Reset</button>
  </div>
  <div class="evt-group">
    <button type="button" id="evt-save" class="evt-primary" disabled>Save</button>
    <span id="evt-status" role="status"></span>
  </div>
</div>
<div id="evt-stage"><iframe id="evt-frame" title="Page preview" src="<?php echo esc_url($frame_url); ?>"></iframe></div>
<script>window.eviveTweakConfig = <?php echo wp_json_encode($config); ?>;</script>
<script src="<?php echo esc_url($base . 'tweak-shell.js?ver=' . EVIVE_VERSION); ?>"></script>
</body>
</html>
        <?php
        exit;
    }

    // ── Block editor ────────────────────────────────────────────────────────

    public static function editor_assets() {
        wp_enqueue_script(
            'evive-tweak-editor',
            plugins_url('assets/tweak-editor.js', EVIVE_PATH . 'evive-connector.php'),
            ['wp-hooks', 'wp-compose', 'wp-element', 'wp-block-editor', 'wp-components'],
            EVIVE_VERSION,
            false
        );
    }
}

Evive_Tweak::init();
