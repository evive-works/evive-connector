<?php
if (!defined('ABSPATH')) exit;

/**
 * Tweak mode (evive #522, design evive #520).
 *
 * 1. Block support attribute `eviveTweak` on every block:
 *      {"desktop"|"tablet"|"phone": {"x": int, "y": int, "width": int, "hidden": bool}}
 *    x / y: move in steps of --evive-tweak-step (the theme's spacing preset 20), -200..200 (evive #582, was -40..40).
 *    width: % of the parent, 10..100 in steps of 5 (images keep their proportions).
 *    hidden: not shown on that screen size.
 *    top / bottom (evive #532): extra space at the top / bottom of a section, in steps of --evive-tweak-step, 0..40.
 *    It is added to the block's own padding (never instead of it), see space_base().
 *    Each screen size has its own range (phone <= 600px, tablet 601-1024px, desktop >= 1025px), so a tweak on one size
 *    never changes another. Rendered as scoped CSS on a class (evive-tw-<hash>); the saved block HTML is not changed,
 *    so the block editor never reports "unexpected content".
 * 2. Tweak mode of the draft preview: ?evive_preview=<preview token>&post_id=<id>&evive_tweak=<tweak token>.
 *    The tweak token comes from the evive app (POST /evive/v1/preview-token/... with tweak_token, tweak_endpoint).
 *    The page then shows a toolbar (Desktop/Tablet/Phone) around an iframe of the same preview with
 *    evive_tweak_frame=1, in which every block of the post content has a block path (data-evive-path, e.g. "2.0.1":
 *    indices of real blocks, skipping whitespace, from the top of the post content) and an overlay script.
 *    The overlay sends the tweaks to the evive app (tweak_endpoint); it never writes to WordPress itself.
 * 3. Tweak basics (evive #575, with the evive MCP #573 and app #574), more keys per screen size, the same lists as the MCP:
 *    z (layer within its section, -5..5; position:relative on the block, isolation on its top-level section),
 *    radius (none|s|m|l|round: the theme's --wp--custom--radius--* presets), shadow (none|soft|medium: the theme's
 *    shadow presets), opacity (100|80|60|40), align (left|center|right: a narrower block in its container), and for
 *    image blocks aspect (orig|16-9|4-3|1-1|3-4: crop with object-fit cover) + focus {x, y} (0..100 %, the focal point).
 *    Duplicate / delete of a block are changes of their own ({path, block, before_hash, duplicate|delete: true}): the
 *    frame gives every block its hash (data-evive-hash, sha256 of its markup as stored, as the MCP computes it).
 * 4. Let it stick out (evive #581, MCP #583): clip "visible" per screen size on a clipping parent (Cover, Group): it is
 *    rendered with overflow:visible, and a cover's image, video and dim layer get border-radius:inherit, so its rounded
 *    corners stay while a moved child (or one brought to front) can stick out of it.
 * 5. One block's size only (evive #582, MCP #583/#586): a block with a width (not a column of Columns) also gets
 *    flex-shrink:0 and flex-basis:auto, and its siblings flex-shrink:0 (zero specificity, through its parent), so in a
 *    flex row (Group row, Buttons) a resize only changes this block and the row may stick out instead; elsewhere these
 *    do nothing. overlay true per screen size takes the block out of the layout
 *    (position:absolute at its own spot; its parent becomes its containing block with a zero-specificity
 *    position:relative), so its size never moves or grows anything else; the tweak frame keeps it where it was by
 *    adding the difference to x / y.
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
    /** @var bool  evive #575: a section has a layer inside it (.evive-tw-iso is printed once). */
    private static $need_iso = false;
    private static $iso_printed = false;
    /** @var array evive #575: path => hash of the blocks of the tweak frame's post. */
    private static $hashes = [];
    /** @var bool  evive #556: the frame is an Elementor page (tweak-elementor.js, no block paths). */
    private static $elementor = false;
    /** @var array evive #575: blocks being rendered (render_block_data .. render_block), to find a block's section. */
    private static $stack = [];

    /** evive #575: the tweak basics' values (the same lists as the evive MCP #573). */
    const BASICS = [
        'radius'  => ['none', 's', 'm', 'l', 'round'],
        'shadow'  => ['none', 'soft', 'medium'],
        'opacity' => [100, 80, 60, 40],
        'align'   => ['left', 'center', 'right'],
        'aspect'  => ['orig', '16-9', '4-3', '1-1', '3-4'],
    ];
    /** evive #575: values that are the same as none (not stored). */
    const BASICS_DEFAULT = ['opacity' => 100, 'aspect' => 'orig'];
    /** evive #575: blocks that can be cropped (aspect / focus), as the MCP allows. */
    const CROP_BLOCKS = ['core/image', 'core/post-featured-image', 'core/cover', 'core/media-text'];
    /** evive #581: a cover's layers that take its rounded corners when it lets children stick out (clip "visible"). */
    const COVER_LAYERS = [' > .wp-block-cover__image-background', ' > .wp-block-cover__video-background', ' > .wp-block-cover__background', ' > .wp-block-cover__gradient-background', ' > .wp-block-cover__embed-background'];

    public static function init() {
        add_filter('register_block_type_args', [__CLASS__, 'add_attribute'], 10, 2);
        add_filter('render_block_data', [__CLASS__, 'block_data'], 10, 3); // evive #575
        add_filter('render_block', [__CLASS__, 'render_block'], 10, 2);
        add_filter('the_content', [__CLASS__, 'annotate_paths'], 8);
        add_action('template_redirect', [__CLASS__, 'maybe_serve'], 2);
        add_action('wp_head', [__CLASS__, 'print_css'], 99);
        add_action('wp_footer', [__CLASS__, 'print_css'], 99);
        add_action('enqueue_block_editor_assets', [__CLASS__, 'editor_assets']);
        add_action('rest_api_init', [__CLASS__, 'rest_routes']); // evive #549
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
                    $v = max(-200, min(200, (int) round($t[$k]))); // evive #582 (MCP #586): was -40..40
                    if ($v !== 0) $c[$k] = $v;
                }
            }
            if (isset($t['width']) && is_numeric($t['width'])) {
                $w = (int) (round($t['width'] / 5) * 5);
                $w = max(10, min(100, $w));
                $c['width'] = $w;
            }
            if (!empty($t['hidden'])) $c['hidden'] = true;
            // evive #532: extra space at the top / bottom, 0..40 steps (0 = none, never negative).
            foreach (['top', 'bottom'] as $k) {
                if (isset($t[$k]) && is_numeric($t[$k])) {
                    $v = max(0, min(40, (int) round($t[$k])));
                    if ($v !== 0) $c[$k] = $v;
                }
            }
            $c += self::sanitize_basics($t); // evive #575
            if ($c) $out[$size] = $c;
        }
        return $out;
    }

    /** evive #575: z, radius, shadow, opacity, align, aspect, focus of one screen size, cleaned as the MCP's tweakBasicsClean(). */
    private static function sanitize_basics(array $t) {
        $c = [];
        if (isset($t['z']) && is_numeric($t['z'])) {
            $z = max(-5, min(5, (int) round($t['z'])));
            if ($z !== 0) $c['z'] = $z;
        }
        foreach (self::BASICS as $k => $list) {
            if (!isset($t[$k]) || is_bool($t[$k]) || $t[$k] === '') continue;
            foreach ($list as $ok) {
                $match = is_int($ok) ? (is_numeric($t[$k]) && (float) $t[$k] === (float) $ok) : $t[$k] === $ok;
                if ($match) {
                    if (!isset(self::BASICS_DEFAULT[$k]) || self::BASICS_DEFAULT[$k] !== $ok) $c[$k] = $ok;
                    break;
                }
            }
        }
        $f = $t['focus'] ?? null;
        if (is_array($f) && isset($f['x'], $f['y']) && is_numeric($f['x']) && is_numeric($f['y'])
            && $f['x'] >= 0 && $f['x'] <= 100 && $f['y'] >= 0 && $f['y'] <= 100) {
            $c['focus'] = ['x' => (int) round($f['x']), 'y' => (int) round($f['y'])];
        }
        if (($t['clip'] ?? null) === 'visible') $c['clip'] = 'visible'; // evive #581: only this value (MCP #583)
        if (($t['overlay'] ?? null) === true) $c['overlay'] = true; // evive #582: only true (MCP #583)
        return $c;
    }

    /** evive #575: the CSS of a radius / shadow value: the theme's presets (evive-base), else core's, never a colour of our own. */
    public static function radius_css($v) {
        $map = apply_filters('evive_tweak_radius', [
            'none'  => '0px',
            's'     => 'var(--wp--custom--radius--s, 4px)',
            'm'     => 'var(--wp--custom--radius--m, 8px)',
            'l'     => 'var(--wp--custom--radius--l, 16px)',
            'round' => '9999px',
        ]);
        return isset($map[$v]) ? (string) $map[$v] : '';
    }
    public static function shadow_css($v) {
        $map = apply_filters('evive_tweak_shadow', [
            'none'   => 'none',
            'soft'   => 'var(--wp--preset--shadow--s, var(--wp--preset--shadow--natural, none))',
            'medium' => 'var(--wp--preset--shadow--m, var(--wp--preset--shadow--deep, none))',
        ]);
        return isset($map[$v]) ? (string) $map[$v] : '';
    }

    /**
     * evive #575: the rules of the basics for one screen size: [rules on the block, [sub selector => rules]].
     * $kind: 'image' (img inside: core/image, featured image), 'cover', 'media-text' or '' (any other block).
     * The same rules are built in tweak-frame.js (basicRules).
     */
    public static function basics_rules(array $t, $kind) {
        $own = [];
        $sub = [];
        $add = function ($sel, $rule) use (&$sub) { $sub[$sel][] = $rule; };
        if (!empty($t['overlay'])) $own[] = 'position:absolute'; // evive #582: out of the layout, at its own spot
        if (!empty($t['z'])) {
            if (empty($t['overlay'])) $own[] = 'position:relative';
            $own[] = 'z-index:' . (int) $t['z'];
        }
        if (isset($t['opacity'])) $own[] = 'opacity:' . ((int) $t['opacity'] / 100);
        if (isset($t['align'])) {
            $m = ['left' => ['0', 'auto'], 'center' => ['auto', 'auto'], 'right' => ['auto', '0']][$t['align']] ?? null;
            if ($m) { $own[] = 'margin-left:' . $m[0] . ' !important'; $own[] = 'margin-right:' . $m[1] . ' !important'; }
        }
        $img = $kind === 'image' ? ' img' : ($kind === 'media-text' ? ' .wp-block-media-text__media img' : '');
        if (isset($t['radius']) && ($r = self::radius_css($t['radius'])) !== '') {
            if ($img !== '') $add($img, 'border-radius:' . $r . ' !important');
            else { $own[] = 'border-radius:' . $r . ' !important'; if ($kind === 'cover' && empty($t['clip'])) $own[] = 'overflow:hidden'; }
        }
        if (isset($t['shadow']) && ($sh = self::shadow_css($t['shadow'])) !== '') {
            if ($img !== '') $add($img, 'box-shadow:' . $sh . ' !important');
            else $own[] = 'box-shadow:' . $sh . ' !important';
        }
        $ratio = isset($t['aspect']) ? str_replace('-', '/', $t['aspect']) : '';
        $pos   = isset($t['focus']) ? sprintf('object-position:%d%% %d%% !important', (int) $t['focus']['x'], (int) $t['focus']['y']) : '';
        if ($kind === 'cover') {
            if ($ratio !== '') { $own[] = 'aspect-ratio:' . $ratio . ' !important'; $own[] = 'min-height:0 !important'; }
            if ($pos !== '') $add(' .wp-block-cover__image-background', $pos);
        } elseif ($img !== '') {
            if ($ratio !== '') {
                $add($img, 'aspect-ratio:' . $ratio . ' !important');
                $add($img, 'height:auto !important');
                $add($img, 'object-fit:cover !important');
            }
            if ($pos !== '') $add($img, $pos);
        }
        if (isset($t['clip']) && $t['clip'] === 'visible') { // evive #581: let children stick out, keep the rounded corners
            $own[] = 'overflow:visible !important';
            if ($kind === 'cover') {
                foreach (self::COVER_LAYERS as $layer) $add($layer, 'border-radius:inherit');
            }
        }
        return [$own, $sub];
    }

    /** evive #575: what kind of media block a block is, for basics_rules(). */
    public static function media_kind($name) {
        if (in_array($name, ['core/image', 'core/post-featured-image', 'core/site-logo'], true)) return 'image';
        if ($name === 'core/cover') return 'cover';
        if ($name === 'core/media-text') return 'media-text';
        return '';
    }

    /**
     * evive #532: a block value from its attributes as CSS ("var:preset|spacing|60" -> var(--wp--preset--spacing--60)).
     * Only plain lengths, var(), calc(), clamp(), min(), max(); anything else gives ''.
     */
    private static function css_value($v) {
        if (is_int($v) || is_float($v)) $v = $v . 'px';
        if (!is_string($v)) return '';
        $v = trim($v);
        if (strpos($v, 'var:') === 0) {
            $parts = explode('|', substr($v, 4));
            foreach ($parts as $part) if (!preg_match('/^[a-z0-9-]+$/i', $part)) return '';
            return 'var(--wp--' . strtolower(implode('--', $parts)) . ')';
        }
        if ($v === '' || strlen($v) > 120 || !preg_match('/^[a-zA-Z0-9.%()\s,+*\/_-]+$/', $v)) return '';
        if (preg_match('/[a-z]+\(/i', $v) && !preg_match('/^(?:(?:var|calc|clamp|min|max)\(|[^(]*$)/i', $v)) return '';
        return $v;
    }

    /**
     * evive #532: what the extra space of a section is added to:
     *   top / bottom: its padding on that side as CSS, resolved like WordPress does: the block's own padding attribute,
     *     else its style variation in the global styles (theme.json, e.g. evive-base's is-style-card), else the block
     *     type's global styles, else core's default for a cover (1em), else '' (= 0; measured under evive-base: a
     *     group with a background has no padding of its own). Padding a theme sets only in its own stylesheet is not
     *     seen (it becomes 0 + n steps).
     *   cover: a cover block (its media is clipped to the padding box),
     *   minh: its own minimum height as CSS ('' = none): the block's attribute, else the block type's global styles,
     *     else core's 430px for a cover (not for a cover with an aspect ratio, which has no minimum height).
     */
    public static function space_base(array $block) {
        $a   = $block['attrs'] ?? [];
        $pad = $a['style']['spacing']['padding'] ?? null;
        $out = ['top' => '', 'bottom' => '', 'cover' => ($block['blockName'] ?? '') === 'core/cover', 'minh' => ''];
        $name = (string) ($block['blockName'] ?? '');
        $global = [];
        $bs = [];
        if ($name !== '' && function_exists('wp_get_global_styles')) {
            // Read the whole blocks tree and look the block up ourselves: wp_get_global_styles() returns the whole
            // style tree (root padding included) for a path that doesn't exist, e.g. core/cover under evive-base.
            $all = wp_get_global_styles(['blocks']);
            $bs  = (is_array($all) && isset($all[$name]) && is_array($all[$name])) ? $all[$name] : [];
            // The block's style variation (is-style-<name>) wins over the block type's own global styles.
            if (preg_match('/(?:^|\s)is-style-([a-z0-9_-]+)/i', (string) ($a['className'] ?? ''), $m)
                && isset($bs['variations'][$m[1]]['spacing']['padding'])) {
                $global = $bs['variations'][$m[1]]['spacing']['padding'];
            } elseif (isset($bs['spacing']['padding'])) {
                $global = $bs['spacing']['padding'];
            }
        }
        foreach (['top', 'bottom'] as $side) {
            $v = self::css_value(is_array($pad) ? ($pad[$side] ?? null) : $pad);
            if ($v === '' && $global !== []) $v = self::css_value(is_array($global) ? ($global[$side] ?? null) : $global);
            if ($v === '' && $name === 'core/cover') $v = '1em';
            $out[$side] = $v;
        }
        if ($out['cover'] && isset($a['minHeight']) && is_numeric($a['minHeight'])) {
            $unit = preg_match('/^(px|em|rem|vh|vw|%|svh|dvh|lvh)$/', (string) ($a['minHeightUnit'] ?? 'px')) ? ($a['minHeightUnit'] ?? 'px') : 'px';
            $out['minh'] = ((float) $a['minHeight']) . $unit;
        } elseif (!empty($a['style']['dimensions']['minHeight'])) {
            $out['minh'] = self::css_value($a['style']['dimensions']['minHeight']);
        } elseif (isset($bs['dimensions']['minHeight']) && self::css_value($bs['dimensions']['minHeight']) !== '') {
            $out['minh'] = self::css_value($bs['dimensions']['minHeight']); // the block type's global styles
        } elseif ($out['cover'] && empty($a['style']['dimensions']['aspectRatio'])) {
            $out['minh'] = '430px'; // core's stylesheet default for a cover without a minimum height of its own
        }
        return $out;
    }

    /**
     * evive #532: CSS declarations for the extra space of one screen size. The same rules are built in tweak-frame.js.
     * padding = calc(resolved padding + n steps), see space_base(); it never touches the block's border (a border set
     * in the block, or a style variation's hairline, stays as it is). A block with its own minimum height gets that
     * much more.
     */
    public static function space_rules(array $t, array $base) {
        $rules = [];
        $total = 0;
        foreach (['top', 'bottom'] as $side) {
            $n = (int) ($t[$side] ?? 0);
            if ($n <= 0) continue;
            $total += $n;
            $own = $base[$side] ?? '';
            if ($own === '' && !empty($base['cover'])) $own = '1em';
            if ($own === '') $own = '0px';
            $rules[] = sprintf('padding-%s:calc(%s + %d * var(--evive-tweak-step)) !important', $side, $own, $n);
        }
        if ($total && !empty($base['minh'])) {
            $rules[] = sprintf('min-height:calc(%s + %d * var(--evive-tweak-step)) !important', $base['minh'], $total);
        }
        return $rules;
    }

    /**
     * The scoped CSS for one tweak on class $cls. $is_image: also scale the <img> inside. $base: see space_base().
     * evive #575: $kind: see media_kind() (crop, corners and shadow of the image inside).
     */
    /**
     * evive #582: $flex: a block with a width also gets flex-shrink:0 / flex-basis:auto (false for a column of Columns,
     * whose width is its flex-basis).
     */
    public static function css($cls, array $tweak, $is_image, array $base = [], $kind = null, $flex = true) {
        if ($kind === null) $kind = $is_image ? 'image' : '';
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
                    if ($flex) { // evive #582: in a flex row only this block changes, its siblings keep their size
                        $rules[] = 'flex-shrink:0';
                        $rules[] = 'flex-basis:auto';
                    }
                }
                $rules = array_merge($rules, self::space_rules($t, $base)); // evive #532
            }
            $sub = [];
            if (empty($t['hidden'])) { // evive #575
                list($own, $sub) = self::basics_rules($t, $kind);
                $rules = array_merge($rules, $own);
            }
            if (!$rules && !$sub) continue;
            $css .= '@media ' . $media[$size] . '{' . ($rules ? $sel . '{' . implode(';', $rules) . '}' : '');
            foreach ($sub as $s => $r) $css .= $sel . $s . '{' . implode(';', $r) . '}';
            if ($flex && !empty($t['width']) && empty($t['hidden'])) {
                // evive #582: its siblings don't shrink either (zero specificity: a flex-shrink of their own wins).
                $css .= ':where(:has(> ' . $sel . ')) > *{flex-shrink:0}';
            }
            if (!empty($t['overlay']) && empty($t['hidden'])) {
                // evive #582: the parent is the overlay's containing block (width % and spot as in the layout). Zero
                // specificity: a parent that is already positioned (relative, sticky, absolute) keeps its own.
                $css .= ':where(:has(> ' . $sel . ')){position:relative}';
            }
            if ($is_image && !empty($t['width']) && empty($t['hidden'])) {
                // Images keep their proportions: the image fills the new width, its height follows.
                $css .= $sel . ' img{width:100% !important;height:auto !important}';
            }
            $css .= '}';
        }
        return $css;
    }

    // ── Rendering ───────────────────────────────────────────────────────────

    /**
     * evive #575: render_block_data: remember the blocks being rendered, so a block with a layer (z) can give its section
     * (the top-level block of the content it is in: a block rendered without a parent block) a stacking context of its
     * own. Every render_block_data has its render_block (WP_Block::render), which takes the entry off again.
     */
    public static function block_data($parsed, $source = null, $parent = null) {
        self::$stack[] = ['root' => $parent === null, 'iso' => false, 'name' => (string) ($parsed['blockName'] ?? '')];
        return $parsed;
    }

    /** evive #575: true when a tweak has a layer (z) on any screen size. */
    private static function has_layer(array $tweak) {
        foreach ($tweak as $t) if (!empty($t['z'])) return true;
        return false;
    }

    public static function render_block($content, $block) {
        // evive #575: this block's entry of block_data() (render_block runs after the inner blocks are done).
        // A block that a plugin rendered itself (pre_render_block) may have left its entry: drop entries down to this block's.
        $name = (string) ($block['blockName'] ?? '');
        $me   = ['root' => false, 'iso' => false, 'name' => $name];
        for ($i = count(self::$stack) - 1; $i >= 0; $i--) {
            if (self::$stack[$i]['name'] === $name) { $me = self::$stack[$i]; array_splice(self::$stack, $i); break; }
        }
        $attrs = $block['attrs'] ?? [];
        $path  = self::$frame ? ($attrs['__evivePath'] ?? null) : null;
        $tweak = isset($attrs[self::ATTR]) ? self::sanitize($attrs[self::ATTR]) : [];
        if ($tweak && !$me['root'] && self::has_layer($tweak)) {
            // The section of this block: the nearest block rendered without a parent (top-level of its content).
            for ($i = count(self::$stack) - 1; $i >= 0; $i--) {
                if (self::$stack[$i]['root']) { self::$stack[$i]['iso'] = true; break; }
            }
        }
        if (!$tweak && $path === null && !$me['iso']) return $content;
        if (!is_string($content) || trim($content) === '') return $content;

        $set = [];
        $add_class = '';
        if ($tweak) {
            $json      = wp_json_encode($tweak);
            $base      = self::has_space($tweak) ? self::space_base($block) : [];
            // evive #532: the class also depends on the block's own padding when there is extra space.
            // evive #575: and on the kind of media block (crop, corners and shadow go on its image).
            $kind      = self::media_kind($block['blockName'] ?? '');
            $flex      = ($block['blockName'] ?? '') !== 'core/column'; // evive #582: a column's width is its flex-basis
            $add_class = 'evive-tw-' . substr(md5($json . ($base ? wp_json_encode($base) : '') . $kind . ($flex ? '' : '|col')), 0, 10);
            $is_image  = $kind === 'image';
            if (!isset(self::$printed[$add_class]) && !isset(self::$pending[$add_class])) {
                self::$pending[$add_class] = self::css($add_class, $tweak, $is_image, $base, $kind, $flex);
            }
            if (self::$frame) $set['data-evive-tweak'] = $json;
        }
        if ($me['iso']) { // evive #575: a layer inside this section stays inside it
            $add_class = trim($add_class . ' evive-tw-iso');
            self::$need_iso = true;
        }
        if ($path !== null) {
            $set['data-evive-path']  = (string) $path;
            $set['data-evive-block'] = (string) ($block['blockName'] ?? '');
            if (!$tweak) $set['data-evive-tweak'] = '{}';
            // evive #532: the overlay builds the same space CSS (space_rules) from this.
            $base = self::space_base($block);
            if ($base['top'] !== '' || $base['bottom'] !== '' || $base['cover'] || $base['minh'] !== '') {
                $set['data-evive-space'] = wp_json_encode($base);
            }
            $text = self::text_source($block);
            if ($text !== null) $set['data-evive-text'] = $text;
            // evive #575: the block's hash as stored (duplicate / delete), see block_hashes().
            if (isset(self::$hashes[(string) $path])) $set['data-evive-hash'] = self::$hashes[(string) $path];
        }
        return self::add_to_first_tag($content, $add_class, $set);
    }

    /** evive #532: true when a tweak has extra space on any screen size. */
    private static function has_space(array $tweak) {
        foreach ($tweak as $t) if (!empty($t['top']) || !empty($t['bottom'])) return true;
        return false;
    }

    /** evive #533: the text element of each block type that can be edited in place (tag pattern in the saved HTML). */
    const TEXT_ELEMENTS = [
        'core/heading'   => 'h[1-6]',
        'core/paragraph' => 'p',
        'core/list-item' => 'li',
        'core/button'    => 'a|button',
        'core/image'     => 'figcaption',
    ];

    /**
     * evive #533: the inner HTML of a block's text element as it is saved in post_content (before wptexturize and other
     * content filters), or null when the block has no editable text (not a text block, holds other blocks, no such
     * element). The overlay edits this source and sends it back as text.before, so the evive MCP finds it unchanged.
     */
    public static function text_source($block) {
        $name = $block['blockName'] ?? '';
        if ($name === 'core/details') return self::details_summary_source($block); // evive #543
        if (!isset(self::TEXT_ELEMENTS[$name]) || !empty($block['innerBlocks'])) return null;
        $html = (string) ($block['innerHTML'] ?? '');
        if (!preg_match('#<(' . self::TEXT_ELEMENTS[$name] . ')\b[^>]*>(.*)</\1\s*>#is', $html, $m)) return null;
        return $m[2];
    }

    /**
     * evive #543: a FAQ (core/details) holds its answer as inner blocks, so its text is the inner HTML of its own
     * <summary>: the one in the block's HTML before its first inner block (innerContent[0]), as the evive MCP (#542)
     * looks for it. Null when there is no such summary or more than one (the MCP refuses those too).
     */
    private static function details_summary_source($block) {
        $own = $block['innerContent'][0] ?? ($block['innerHTML'] ?? '');
        if (!is_string($own)) return null;
        if (preg_match_all('#<summary\b[^>]*>(.*?)</summary\s*>#is', $own, $m) !== 1) return null;
        return $m[1][0];
    }

    /** Add a class (or several, space separated) and attributes to the first HTML tag of a block's output. */
    private static function add_to_first_tag($html, $class, array $set) {
        if (class_exists('WP_HTML_Tag_Processor')) {
            $p = new WP_HTML_Tag_Processor($html);
            if (!$p->next_tag()) return $html;
            if ($class) foreach (preg_split('/\s+/', $class) as $c) $p->add_class($c);
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
        if (!self::$frame || self::$elementor || (int) get_the_ID() !== self::$post_id) return $content;
        if (!function_exists('has_blocks') || !has_blocks($content)) return $content;
        $blocks = parse_blocks($content);
        self::walk($blocks, '');
        self::$hashes = self::block_hashes((string) get_post_field('post_content', self::$post_id, 'raw')); // evive #575
        return serialize_blocks($blocks);
    }

    /**
     * evive #575: path => sha256 of every block's markup as stored in post_content (from its opening comment to its
     * closing comment), found the way the evive MCP does (blockPaths, same delimiter pattern), so the hash is the
     * before_hash of a duplicate / delete. Markup the MCP can't read gives [] (no duplicate / delete in the frame).
     */
    public static function block_hashes($content) {
        $re = '/<!--\s+(\/)?wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)\s+(\{[\s\S]*?\}\s+)?(\/)?-->/';
        if (!preg_match_all($re, $content, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return [];
        $out = []; $stack = []; $counters = [0];
        foreach ($all as $m) {
            $at = $m[0][1];
            $len = strlen($m[0][0]);
            $name = $m[2][0];
            if (isset($m[1]) && $m[1][1] >= 0 && $m[1][0] === '/') {
                $open = array_pop($stack);
                if (!$open || $open['name'] !== $name) return [];
                array_pop($counters);
                $out[$open['path']] = hash('sha256', substr($content, $open['start'], $at + $len - $open['start']));
                continue;
            }
            $idx  = $counters[count($counters) - 1]++;
            $path = $stack ? $stack[count($stack) - 1]['path'] . '.' . $idx : (string) $idx;
            if (isset($m[4]) && $m[4][1] >= 0 && $m[4][0] === '/') {
                $out[$path] = hash('sha256', $m[0][0]);
                continue;
            }
            $stack[] = ['path' => $path, 'name' => $name, 'start' => $at];
            $counters[] = 0;
        }
        return $stack ? [] : $out;
    }

    private static function walk(array &$blocks, $prefix, $first = 0) {
        $i = $first; // evive #549: a section rendered on its own starts at its own index
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
        if (self::$need_iso && !self::$iso_printed) { // evive #575
            $css .= '.evive-tw-iso{isolation:isolate}';
            self::$iso_printed = true;
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
     * evive #556: $builder 'elementor' opens the Elementor tweak frame (only for an Elementor page); '' = blocks.
     * Returns [token expiry, error message or ''].
     */
    public static function store_token($post_id, $token, $endpoint, $builder = '') {
        $token = (string) $token;
        if (!preg_match('/^[A-Za-z0-9_-]{32,128}$/', $token)) return [0, 'tweak_token must be 32-128 characters A-Z a-z 0-9 _ -'];
        $endpoint = trim((string) $endpoint);
        if ($endpoint !== '' && !self::endpoint_ok($endpoint)) return [0, 'tweak_endpoint must be an https URL'];
        $builder = (string) $builder;
        if ($builder !== '' && $builder !== 'blocks' && $builder !== 'elementor') return [0, 'builder must be blocks or elementor'];
        if ($builder === 'elementor' && !Evive_Tweak_Elementor::supports($post_id)) {
            return [0, 'This page is not an Elementor page (or its Elementor data cannot be read), so it has no Elementor tweak view.'];
        }
        $expires = time() + self::TOKEN_TTL;
        update_post_meta($post_id, '_evive_tweak_token_hash', hash('sha256', $token));
        update_post_meta($post_id, '_evive_tweak_token_expires', $expires);
        update_post_meta($post_id, '_evive_tweak_endpoint', esc_url_raw($endpoint));
        update_post_meta($post_id, '_evive_tweak_builder', $builder === 'elementor' ? 'elementor' : 'blocks'); // evive #556
        return [$expires, ''];
    }

    /** evive #556: the tweak view of this post: 'elementor' when its token was made for the Elementor frame, else 'blocks'. */
    public static function builder_of($post_id) {
        return get_post_meta($post_id, '_evive_tweak_builder', true) === 'elementor' && Evive_Tweak_Elementor::supports($post_id) ? 'elementor' : 'blocks';
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

    // ── Layout preview and media library (evive #549) ───────────────────────

    /**
     * evive #549: two routes for the tweak frame, with the tweak token of the page (header X-Evive-Tweak) instead of a
     * WordPress login. Neither writes anything.
     *   POST /evive/v1/tweak/render {post_id, path, markup}: the block markup of one section (a new layout from evive) as
     *     the site renders it, with the tweak frame's data attributes (paths from `path`), plus the block styles it needs.
     *   GET  /evive/v1/tweak/media?post_id=&search=&page=: the images of the media library (for Replace image).
     */
    public static function rest_routes() {
        $auth = [__CLASS__, 'rest_token_ok'];
        register_rest_route('evive/v1', '/tweak/render', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_render'], 'permission_callback' => $auth,
        ]);
        register_rest_route('evive/v1', '/tweak/media', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_media'], 'permission_callback' => $auth,
        ]);
    }

    /** evive #549: the tweak token of post_id, from the X-Evive-Tweak header. */
    public static function rest_token_ok(WP_REST_Request $req) {
        $post_id = (int) $req->get_param('post_id');
        $token   = (string) $req->get_header('x_evive_tweak');
        if (!$post_id || !get_post($post_id) || !self::token_valid($post_id, $token)) {
            return new WP_Error('evive_tweak_token', 'This tweak link is not valid any more. Open tweak mode again from evive.', ['status' => 401]);
        }
        return true;
    }

    /** evive #549: render one section's block markup (nothing is saved). */
    public static function rest_render(WP_REST_Request $req) {
        $post_id = (int) $req->get_param('post_id');
        $path    = (string) $req->get_param('path');
        $markup  = (string) $req->get_param('markup');
        if (!preg_match('/^\d{1,4}$/', $path)) return new WP_Error('evive_tweak_path', 'path must be the number of a top-level section', ['status' => 400]);
        if ($markup === '' || strlen($markup) > 300000) return new WP_Error('evive_tweak_markup', 'markup must be 1 to 300000 bytes of block markup', ['status' => 400]);
        $blocks = parse_blocks($markup);
        $real   = array_values(array_filter($blocks, function ($b) { return !empty($b['blockName']); }));
        if (count($real) !== 1) return new WP_Error('evive_tweak_markup', 'markup must hold exactly one top-level block', ['status' => 400]);
        // The same as the evive MCP refuses at Save (#547): no raw HTML, no scripts.
        $unsafe = false;
        $check  = function ($list) use (&$check, &$unsafe) {
            foreach ($list as $b) {
                if (in_array($b['blockName'] ?? '', ['core/html', 'core/freeform', 'core/shortcode', 'core/legacy-widget'], true)) $unsafe = true;
                if (!empty($b['innerBlocks'])) $check($b['innerBlocks']);
            }
        };
        $check($real);
        if ($unsafe || preg_match('/<\s*(script|iframe|object|embed|style|form)\b|<[a-z][^>]*\son[a-z]+\s*=|\b(?:href|src)\s*=\s*["\']?\s*javascript:/i', $markup)) {
            return new WP_Error('evive_tweak_markup', 'This markup holds HTML that tweak mode does not show (raw HTML, a script, an iframe or an event attribute).', ['status' => 400]);
        }

        // Render as the tweak frame does: block paths and data attributes, in the context of the page.
        global $post;
        $old_post = $post;
        $post     = get_post($post_id);
        setup_postdata($post);
        self::$frame   = true;
        self::$post_id = $post_id;
        self::walk($real, '', (int) $path);

        $styles = wp_styles();
        $queue  = $styles->queue;
        $after  = [];
        foreach ($styles->registered as $h => $s) $after[$h] = count((array) ($s->extra['after'] ?? []));

        $html = '';
        foreach ($real as $b) $html .= render_block($b);
        $html = wptexturize($html);
        if (function_exists('wp_filter_content_tags')) $html = wp_filter_content_tags($html, 'the_content');

        // The styles the blocks asked for: block stylesheets (a block type not on the page yet has none loaded),
        // their inline additions, and the layout / spacing CSS that block supports make for this markup.
        $out = [];
        foreach ($styles->queue as $h) {
            $s = $styles->registered[$h] ?? null;
            if (!$s) continue;
            $new_inline = array_slice((array) ($s->extra['after'] ?? []), in_array($h, $queue, true) ? ($after[$h] ?? 0) : 0);
            $href = '';
            if (!in_array($h, $queue, true) && is_string($s->src) && $s->src !== '') {
                $href = $s->src;
                if (!preg_match('#^(https?:)?//#', $href)) $href = $styles->base_url . $href;
                if ($s->ver !== null) $href = add_query_arg('ver', $s->ver ?: $styles->default_version, $href); // as WP_Styles::_css_href
            }
            $inline = implode("\n", array_filter(array_map('strval', $new_inline)));
            if ($href !== '' || $inline !== '') $out[] = ['id' => $h, 'href' => $href, 'inline' => $inline];
        }
        $css = function_exists('wp_style_engine_get_stylesheet_from_context')
            ? (string) wp_style_engine_get_stylesheet_from_context('block-supports', ['prettify' => false]) : '';
        // Layout classes are numbered per request (wp-container-core-group-is-layout-1): the page has its own with the
        // same numbers, so the section's get a suffix in its HTML and CSS alike.
        $sfx = '-evt' . strtolower(wp_generate_password(6, false, false));
        $ren = function ($t) use ($sfx) {
            return preg_replace('/\b(wp-(?:container|elements)-[a-z0-9-]*?\d+)(?![a-z0-9-])/', '$1' . $sfx, (string) $t);
        };
        $html = $ren($html);
        $css  = $ren($css);
        foreach ($out as &$o) $o['inline'] = $ren($o['inline']);
        unset($o);

        self::$frame = false;
        $post = $old_post;
        if ($old_post) setup_postdata($old_post); else wp_reset_postdata();

        $res = rest_ensure_response(['path' => $path, 'html' => $html, 'styles' => $out, 'css' => (string) $css]);
        $res->header('Cache-Control', 'no-store');
        return $res;
    }

    /** evive #549: images of the media library, newest first, 24 per page; search as the media library does (title, caption, description). */
    public static function rest_media(WP_REST_Request $req) {
        $search = trim(sanitize_text_field((string) $req->get_param('search')));
        $page   = max(1, (int) $req->get_param('page'));
        $args   = [
            'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image',
            'posts_per_page' => 24, 'paged' => $page, 'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => false,
        ];
        if ($search !== '') $args['s'] = $search;
        $q     = new WP_Query($args);
        $items = [];
        foreach ($q->posts as $a) {
            $url = wp_get_attachment_url($a->ID);
            if (!$url) continue;
            $thumb = wp_get_attachment_image_src($a->ID, 'medium');
            $meta  = wp_get_attachment_metadata($a->ID);
            $items[] = [
                'id'        => $a->ID,
                'title'     => get_the_title($a),
                'alt'       => (string) get_post_meta($a->ID, '_wp_attachment_image_alt', true),
                'url'       => $url,
                'thumb'     => $thumb ? $thumb[0] : $url,
                'width'     => (int) ($meta['width'] ?? 0) ?: null,
                'height'    => (int) ($meta['height'] ?? 0) ?: null,
                'mime_type' => get_post_mime_type($a),
            ];
        }
        $res = rest_ensure_response(['items' => $items, 'page' => $page, 'pages' => (int) $q->max_num_pages, 'total' => (int) $q->found_posts]);
        $res->header('Cache-Control', 'no-store');
        return $res;
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
            if (self::builder_of($post_id) === 'elementor') { // evive #556: the Elementor overlay instead of the block one
                self::$elementor = true;
                Evive_Tweak_Elementor::serving($post_id);
                add_action('wp_enqueue_scripts', function () use ($post_id, $tweak) { Evive_Tweak_Elementor::enqueue_frame($post_id, $tweak); }, 20);
                return;
            }
            add_action('wp_enqueue_scripts', function () use ($post_id, $tweak) {
                wp_enqueue_style('evive-tweak-frame', plugins_url('assets/tweak-frame.css', EVIVE_PATH . 'evive-connector.php'), [], EVIVE_VERSION);
                wp_enqueue_script('evive-tweak-frame', plugins_url('assets/tweak-frame.js', EVIVE_PATH . 'evive-connector.php'), [], EVIVE_VERSION, true);
                wp_localize_script('evive-tweak-frame', 'eviveTweakFrame', ['media' => self::media(),
                    // evive #549: the layout preview renders through the site's own REST route (tweak token).
                    'rest' => rest_url('evive/v1/tweak/'), 'post_id' => $post_id, 'token' => $tweak]);
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
            'rest'     => rest_url('evive/v1/tweak/'), // evive #549: the media library for Replace image
        ];
        $elementor = self::builder_of($post_id) === 'elementor'; // evive #556
        if ($elementor) {
            $config['builder'] = 'elementor';
            $config['widths']  = Evive_Tweak_Elementor::widths($config['widths']);
        }
        // evive #539: tweaking the pending copy of a live page: the bar shows the band and Publish / Discard changes.
        $live_id = class_exists('Evive_Pending') ? Evive_Pending::live_of($post_id) : 0;
        if ($live_id) {
            $config['pending'] = ['live_id' => $live_id, 'live_url' => get_permalink($live_id)];
        }
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
<body<?php echo $elementor ? ' class="evt-elementor"' : ''; ?>>
<?php if ($live_id) : // evive #539 ?>
<div id="evt-pending" role="note">
  <span>Pending changes of a live page: visitors still see the live version</span>
  <span class="evt-group">
    <button type="button" id="evt-publish" class="evt-primary">Publish changes</button>
    <button type="button" id="evt-discard">Discard changes</button>
  </span>
</div>
<?php endif; ?>
<div id="evt-bar" role="toolbar" aria-label="evive tweak">
  <div class="evt-group" id="evt-devices">
    <button type="button" data-device="desktop" aria-pressed="true">Desktop</button>
    <button type="button" data-device="tablet" aria-pressed="false">Tablet</button>
    <button type="button" data-device="phone" aria-pressed="false">Phone</button>
  </div>
  <div class="evt-group evt-sel" id="evt-sel">Click a block to select it</div>
  <div class="evt-group" id="evt-tools">
    <button type="button" data-act="edittext" title="Edit the text of this block (or double-click it)">Edit text</button>
    <button type="button" data-act="toggleopen" aria-pressed="false"<?php echo $elementor ? ' hidden' : ''; ?> title="Open or close the selected FAQ, to reach its answer (clicks on its question don't, while tweaking)">Open FAQ</button>
    <button type="button" data-act="parent" title="Select the parent block (click the block again)">Parent</button>
    <button type="button" data-act="smaller" title="Smaller (5 %)">&minus;</button>
    <button type="button" data-act="bigger" title="Bigger (5 %)">+</button>
    <button type="button" data-act="shorter" title="Less space at the bottom (Shift: 5 steps)" hidden>Shorter</button>
    <button type="button" data-act="taller" title="More space at the bottom (Shift: 5 steps)" hidden>Taller</button>
    <button type="button" data-act="hidephone" aria-pressed="false" title="Hide this block on phones">Hide on phone</button>
<?php // evive #575: tweak basics (menus are filled by tweak-shell.js); evive #578: on Elementor pages too ?>
    <span class="evt-menu"><button type="button" data-act="menu" data-menu="layer" aria-haspopup="menu" aria-expanded="false" title="Bring this block to the front or send it to the back, within its section (this screen size)">Layer</button></span>
    <button type="button" data-act="stickout" hidden>Let it stick out</button><?php // evive #581: shown when a block around it cuts it off ?>
    <button type="button" data-act="overlay" aria-pressed="false"<?php echo $elementor ? ' hidden' : ''; // evive #582: block pages only ?> title="Overlay: take this block out of the layout, where it is now, so its size never moves or grows anything else (this screen size)">Overlay</button>
    <span class="evt-menu"><button type="button" data-act="menu" data-menu="style" aria-haspopup="menu" aria-expanded="false" title="Corners, shadow and opacity (all screen sizes)">Style</button></span>
    <span class="evt-seg" role="group" aria-label="Align in its container (this screen size)">
      <button type="button" data-act="align" data-val="left" aria-pressed="false" aria-label="Align left" title="Align left in its container (a narrower block)"><svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false"><path d="M2 2v12M5 5h8v6H5z" fill="none" stroke="currentColor" stroke-width="1.6"/></svg></button>
      <button type="button" data-act="align" data-val="center" aria-pressed="false" aria-label="Align center" title="Center in its container (a narrower block)"><svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false"><path d="M8 1v14M4 5h8v6H4z" fill="none" stroke="currentColor" stroke-width="1.6"/></svg></button>
      <button type="button" data-act="align" data-val="right" aria-pressed="false" aria-label="Align right" title="Align right in its container (a narrower block)"><svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false"><path d="M14 2v12M3 5h8v6H3z" fill="none" stroke="currentColor" stroke-width="1.6"/></svg></button>
    </span>
    <span class="evt-menu"><button type="button" data-act="menu" data-menu="crop" aria-haspopup="menu" aria-expanded="false" title="Crop this image to a shape and pick its focal point (this screen size)">Crop</button></span>
    <button type="button" data-act="duplicate" aria-pressed="false" title="Put a copy of this block right after it (on Save)">Duplicate</button>
    <button type="button" data-act="delete" aria-pressed="false" title="Remove this block from the page (on Save; undo through the revision in evive)">Delete</button>
<?php if ($elementor) : // evive #556: Elementor pages ?>
    <button type="button" data-act="hidetablet" aria-pressed="false" title="Hide this element on tablets">Hide on tablet</button>
    <button type="button" data-act="moveup" title="Move this element up within its parent">Move up</button>
    <button type="button" data-act="movedown" title="Move this element down within its parent">Move down</button>
<?php endif; ?>
    <button type="button" data-act="layout"<?php echo $elementor ? ' hidden' : ''; // evive #556: not on Elementor pages ?> title="Change the layout of this section (a pattern of the theme), with all its text">Change layout</button>
    <button type="button" data-act="image"<?php echo $elementor ? ' hidden' : ''; ?> title="Put another image here: upload one (or drop it on the image) or pick one from the media library">Replace image</button>
    <button type="button" data-act="reset" title="Undo the image, text edit or new layout of this block, or else its tweaks on this screen size">Reset</button>
  </div>
  <div class="evt-group">
    <button type="button" id="evt-save" class="evt-primary" disabled>Save</button>
    <span id="evt-status" role="status"></span>
  </div>
</div>
<div id="evt-stage"><iframe id="evt-frame" title="Page preview" src="<?php echo esc_url($frame_url); ?>"></iframe>
<div id="evt-panel" role="dialog" aria-modal="false" aria-labelledby="evt-panel-title" hidden></div><?php // evive #549: Change layout / Replace image ?>
</div>
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
            ['wp-hooks', 'wp-compose', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-data', 'wp-notices'],
            EVIVE_VERSION,
            false
        );
        // evive #539: a notice on a pending copy ("publish from evive") and on a live page that has one.
        $post = get_post();
        $pending = ($post && class_exists('Evive_Pending')) ? Evive_Pending::editor_data($post->ID) : null;
        if ($pending) wp_localize_script('evive-tweak-editor', 'eviveTweakEditor', ['pending' => $pending]);
    }
}

Evive_Tweak::init();
