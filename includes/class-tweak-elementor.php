<?php
if (!defined('ABSPATH')) exit;

/**
 * Tweak mode for Elementor pages (evive #556; MCP #554 evive_apply_elementor_tweak, APP #555; decisions E1-E5 on the card).
 *
 * The evive app asks for an Elementor tweak view with POST /evive/v1/preview-token/{type}/{id}
 * {tweak_token, tweak_endpoint, builder: "elementor", base_hash}; the Connector answers with tweak_builder: "elementor"
 * (only for a page with _elementor_edit_mode = builder), and the tweak shell (class-tweak.php) then serves this frame:
 * the page as the site renders it, with tweak-elementor.js instead of the block overlay. The frame gets the page's own
 * elements from _elementor_data (id, type, parent, label, the editable texts as saved (E3), margin/padding/width/hidden
 * per device) and base_hash = sha256 of _elementor_data as stored, the same hash as the MCP reads through /item (E2).
 * It marks the elements of the page's own content in the DOM by their data-id (E4: header/footer/Theme Builder
 * templates have their own data-elementor-id and are never marked; global widgets are marked but not selectable).
 * Save sends the MCP's change set {base_hash, changes: [{type: text|spacing|width|hidden|move, id, ...}]} to the evive
 * app (tweak_endpoint); nothing here writes to WordPress.
 *
 * GET /evive/v1/tweak/elementor?post_id= (tweak token in X-Evive-Tweak): the same element list and base_hash, read only.
 * The route also tells the evive app (#555) that this Connector has the Elementor tweak frame (namespace index).
 */
class Evive_Tweak_Elementor {

    /** E3: the texts that can be edited in place per widget type (the MCP's ELT_TEXT). */
    const TEXT = [
        'heading'     => ['title'],
        'text-editor' => ['editor'],
        'button'      => ['text'],
        'image'       => ['caption'],
        'icon-list'   => ['icon_list.*.text'],
        'testimonial' => ['testimonial_content', 'testimonial_name', 'testimonial_job'],
    ];
    const DEVICES = ['desktop' => '', 'tablet' => '_tablet', 'mobile' => '_mobile'];

    /** @var int The post of the Elementor tweak frame being served (0 = none). */
    private static $frame_post = 0;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'rest_routes']);
        add_filter('get_post_metadata', [__CLASS__, 'no_element_cache'], 10, 3);
    }

    /**
     * The frame shows the page exactly as stored (the elements and base_hash come from _elementor_data): Elementor's
     * element cache of the page, which can be older after a write that did not clear it, is not used there.
     */
    public static function no_element_cache($value, $object_id, $meta_key) {
        if (self::$frame_post && $meta_key === '_elementor_element_cache' && (int) $object_id === self::$frame_post) return [''];
        return $value;
    }

    /** Called by Evive_Tweak::maybe_serve for the Elementor frame (before rendering starts). */
    public static function serving($post_id) {
        self::$frame_post = (int) $post_id;
    }

    public static function rest_routes() {
        register_rest_route('evive/v1', '/tweak/elementor', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_elements'], 'permission_callback' => ['Evive_Tweak', 'rest_token_ok'],
        ]);
    }

    public static function rest_elements(WP_REST_Request $req) {
        $post_id = (int) $req->get_param('post_id');
        $d = self::read($post_id);
        if (!$d) return new WP_Error('evive_not_elementor', 'This page has no Elementor data.', ['status' => 409]);
        $res = rest_ensure_response(['post_id' => $post_id, 'base_hash' => $d['hash'], 'elements' => $d['elements'], 'breakpoints' => self::breakpoints()]);
        $res->header('Cache-Control', 'no-store');
        return $res;
    }

    /** True when this post is an Elementor page whose data tweak mode can read. */
    public static function supports($post_id) {
        return function_exists('evive_is_elementor_post') && evive_is_elementor_post((int) $post_id) && self::read((int) $post_id) !== null;
    }

    /**
     * The page's Elementor data as stored: ['hash' => sha256 hex of _elementor_data (as the Connector's /item gives it raw),
     * 'elements' => [...]], or null when there is none or it is not valid JSON.
     */
    public static function read($post_id) {
        $raw = get_post_meta((int) $post_id, '_elementor_data', true);
        if (!is_string($raw) || trim($raw) === '') return null;
        $tree = json_decode($raw, true);
        if (!is_array($tree)) return null;
        $flat = [];
        $ids  = [];
        self::walk($tree, null, null, $flat, $ids);
        $out = [];
        foreach ($flat as $e) $out[] = self::row($e, ($ids[$e['id']] ?? 0) > 1);
        return ['hash' => hash('sha256', $raw), 'elements' => $out];
    }

    private static function walk(array $list, $parent, $top, array &$flat, array &$ids) {
        foreach (array_values($list) as $i => $el) {
            if (!is_array($el)) continue;
            $id = isset($el['id']) ? (string) $el['id'] : '';
            $t  = $top ?? $i + 1;
            if ($id !== '') {
                $ids[$id] = ($ids[$id] ?? 0) + 1;
                $flat[] = ['id' => $id, 'el' => $el, 'parent' => $parent, 'index' => $i, 'siblings' => count($list), 'top' => $t];
            }
            if (!empty($el['elements']) && is_array($el['elements'])) self::walk($el['elements'], $id !== '' ? $id : $parent, $t, $flat, $ids);
        }
    }

    private static function plain($html) {
        $t = wp_strip_all_tags(str_replace('<', ' <', (string) $html));
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /** One element as the frame needs it (the same rules as the MCP's read: true). */
    private static function row(array $e, $dup) {
        $el   = $e['el'];
        $type = (string) ($el['elType'] ?? '');
        $wt   = isset($el['widgetType']) ? (string) $el['widgetType'] : '';
        $s    = (isset($el['settings']) && is_array($el['settings'])) ? $el['settings'] : [];
        if ($type === 'widget') {
            $txt = '';
            foreach (['title', 'editor', 'text', 'testimonial_content', 'caption'] as $k) {
                if (isset($s[$k]) && is_string($s[$k])) { $txt = $s[$k]; break; }
            }
            $txt   = function_exists('mb_substr') ? mb_substr(self::plain($txt), 0, 40) : substr(self::plain($txt), 0, 40);
            $label = $txt !== '' ? $wt . ' "' . $txt . '"' : $wt;
        } else {
            $label = $e['parent'] ? $type : $type . ' ' . $e['top'];
        }
        $row = ['id' => $e['id'], 'el_type' => $type, 'parent' => $e['parent'], 'index' => $e['index'], 'siblings' => $e['siblings'], 'label' => $label];
        if ($wt !== '') $row['widget_type'] = $wt;
        if (!empty($el['isInner'])) $row['inner'] = true;
        $global = $type === 'widget' && ($wt === 'global' || array_key_exists('templateID', $el));
        $atomic = strpos($type, 'e-') === 0 || strpos($wt, 'e-') === 0;
        if ($global || $atomic || $dup || !in_array($type, ['section', 'column', 'container', 'widget'], true)) {
            $row['selectable'] = false;
            $row['why'] = $global ? 'global widget' : ($dup ? 'id used twice' : ($atomic ? 'Elementor 4 element' : 'unknown type'));
            return $row;
        }
        // E3: the saved source of the editable texts (not dynamic ones, not a caption from the media library).
        $texts = [];
        $dyn   = (isset($s['__dynamic__']) && is_array($s['__dynamic__'])) ? $s['__dynamic__'] : [];
        foreach (self::TEXT[$wt] ?? [] as $f) {
            $key = explode('.', $f)[0];
            if (!empty($dyn[$key])) continue;
            if ($wt === 'image' && ($s['caption_source'] ?? '') !== 'custom') continue;
            if (strpos($f, '.*.') !== false) {
                [$k, , $sub] = explode('.', $f);
                foreach ((isset($s[$k]) && is_array($s[$k])) ? array_values($s[$k]) : [] as $i => $it) {
                    if (is_array($it)) $texts[$k . '.' . $i . '.' . $sub] = (string) ($it[$sub] ?? '');
                }
            } elseif (isset($s[$f]) && is_string($s[$f])) {
                $texts[$f] = $s[$f];
            }
        }
        if ($texts) $row['texts'] = $texts;
        // Margin/padding (widgets: _margin/_padding), width and hidden per device, as saved.
        $pre = $type === 'widget' ? '_' : '';
        $wk  = self::width_key($type, $s);
        if ($wk) $row['width_units'] = $wk['units'];
        $dev = [];
        foreach (self::DEVICES as $d => $sfx) {
            $v = [];
            foreach (['margin', 'padding'] as $p) {
                $b = self::dims_back($s[$pre . $p . $sfx] ?? null);
                if ($b) { $v[$p] = $b; $v[$p . '_unit'] = (string) (($s[$pre . $p . $sfx]['unit'] ?? '') ?: 'px'); }
            }
            $w = $wk ? self::width_now($s, $wk, $sfx) : null;
            if ($w) $v['width'] = $w;
            if (!empty($s['hide_' . $d])) $v['hidden'] = true;
            if ($v) $dev[$d] = $v;
        }
        if ($dev) $row['devices'] = $dev;
        return $row;
    }

    private static function dims_back($v) {
        if (!is_array($v)) return null;
        $any = false;
        $out = [];
        foreach (['top', 'right', 'bottom', 'left'] as $k) {
            $x = $v[$k] ?? '';
            if ($x !== '' && $x !== null) { $any = true; $out[$k] = (float) $x; } else { $out[$k] = null; }
        }
        return $any ? $out : null;
    }

    /** Where an element's width lives (the MCP's eltWidthKey). */
    private static function width_key($type, array $s) {
        if ($type === 'widget') return ['key' => '_element_custom_width', 'kind' => 'widget', 'units' => ['%', 'px', 'vw']];
        if ($type === 'column') return ['key' => '_inline_size', 'kind' => 'column', 'units' => ['%']];
        if ($type === 'container') return ['key' => ($s['content_width'] ?? '') === 'full' ? 'width' : 'boxed_width', 'kind' => 'slider', 'units' => ['%', 'px', 'vw']];
        if ($type === 'section') return ($s['layout'] ?? '') === 'full_width' ? null : ['key' => 'content_width', 'kind' => 'slider', 'units' => ['px', '%', 'vw']];
        return null;
    }

    private static function width_now(array $s, array $wk, $sfx) {
        $v = $s[$wk['key'] . $sfx] ?? null;
        if ($wk['kind'] === 'column') return ($v === null || $v === '') ? null : ['value' => (float) $v, 'unit' => '%'];
        if ($wk['kind'] === 'widget' && ($s['_element_width' . $sfx] ?? '') !== 'initial') return null;
        if (is_array($v) && isset($v['size']) && $v['size'] !== '' && $v['size'] !== null) return ['value' => (float) $v['size'], 'unit' => (string) (($v['unit'] ?? '') ?: 'px')];
        return null;
    }

    /** Elementor's breakpoints (max widths of mobile and tablet); defaults 767 / 1024. */
    public static function breakpoints() {
        $bp = ['mobile' => 767, 'tablet' => 1024];
        try {
            if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->breakpoints)) {
                foreach (\Elementor\Plugin::$instance->breakpoints->get_active_breakpoints() as $name => $b) {
                    if (isset($bp[$name]) && is_object($b) && method_exists($b, 'get_value') && (int) $b->get_value() > 0) $bp[$name] = (int) $b->get_value();
                }
            }
        } catch (\Throwable $e) { /* keep the defaults */ }
        return $bp;
    }

    /** Media queries per device of the tweak view (desktop / tablet / phone), from Elementor's breakpoints. */
    public static function media() {
        $bp = self::breakpoints();
        return [
            'desktop' => '(min-width: ' . ($bp['tablet'] + 1) . 'px)',
            'tablet'  => '(min-width: ' . ($bp['mobile'] + 1) . 'px) and (max-width: ' . $bp['tablet'] . 'px)',
            'phone'   => '(max-width: ' . $bp['mobile'] . 'px)',
        ];
    }

    /** The frame widths of the shell: the tablet and phone frames must fall inside Elementor's tablet and mobile ranges. */
    public static function widths(array $w) {
        $bp = self::breakpoints();
        $w['desktop'] = max((int) ($w['desktop'] ?? 1280), $bp['tablet'] + 1);
        $w['tablet']  = min(max((int) ($w['tablet'] ?? 820), $bp['mobile'] + 1), $bp['tablet']);
        $w['phone']   = min((int) ($w['phone'] ?? 390), $bp['mobile']);
        return $w;
    }

    /** The frame of an Elementor page: the overlay instead of the block overlay (called from Evive_Tweak::maybe_serve). */
    public static function enqueue_frame($post_id, $token) {
        $d = self::read($post_id);
        self::$frame_post = (int) $post_id;
        wp_enqueue_style('evive-tweak-frame', plugins_url('assets/tweak-frame.css', EVIVE_PATH . 'evive-connector.php'), [], EVIVE_VERSION);
        wp_enqueue_style('evive-tweak-elementor', plugins_url('assets/tweak-elementor.css', EVIVE_PATH . 'evive-connector.php'), ['evive-tweak-frame'], EVIVE_VERSION);
        wp_enqueue_script('evive-tweak-elementor', plugins_url('assets/tweak-elementor.js', EVIVE_PATH . 'evive-connector.php'), [], EVIVE_VERSION, true);
        wp_localize_script('evive-tweak-elementor', 'eviveTweakElementor', [
            'post_id'   => (int) $post_id,
            'base_hash' => $d ? $d['hash'] : '',
            'elements'  => $d ? $d['elements'] : [],
            'media'     => self::media(),
            'rest'      => rest_url('evive/v1/tweak/'),
            'token'     => $token,
        ]);
    }
}

Evive_Tweak_Elementor::init();
