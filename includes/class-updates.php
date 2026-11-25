<?php
if (!defined('ABSPATH')) exit;

/**
 * Update log (evive #570, design #569 R2): what WordPress or the host updated on this site, and what is still waiting.
 * Feeds the "Updates" tile of evive's monthly site report.
 *
 * - Every core, plugin or theme that an upgrader run puts in place is logged as {type, slug, name, from, to, time}.
 *   "from" is read just before the files are replaced (upgrader_source_selection, the stored plugin/theme headers),
 *   "to" after (the new files). A plugin or theme that was not there before has from = null.
 *   Logged in upgrader_process_complete. One option, not autoloaded, the last 100 entries. On Multisite one log for
 *   the whole network (a network option): updates are network-wide.
 * - GET /evive/v1/updates?since=<ISO date>: {installed, waiting: {core, plugins, themes, items}, log_since, log_version}.
 *   Read only. Waiting comes from the update_core / update_plugins / update_themes site transients (no forced check).
 *
 * Nothing here changes how or when WordPress updates; evive installing updates itself is a later design (R7).
 */
class Evive_Updates {

    const OPTION = 'evive_update_log';
    const MAX    = 100;

    /** Core version when this request started (before a core update replaces version.php). */
    private static $core_before = null;
    /** "type:slug" => ['name' => ..., 'version' => ...] or null (not installed), read before the files were replaced. */
    private static $before = [];
    /** Items whose package was installed successfully in this request, waiting for upgrader_process_complete. */
    private static $done = [];

    public static function init() {
        self::$core_before = isset($GLOBALS['wp_version']) ? (string) $GLOBALS['wp_version'] : null;
        add_filter('upgrader_source_selection', [__CLASS__, 'remember_before'], 999, 4);
        add_filter('upgrader_install_package_result', [__CLASS__, 'remember_done'], 999, 2);
        add_action('upgrader_process_complete', [__CLASS__, 'log_run'], 10, 2);
        add_action('shutdown', [__CLASS__, 'flush']); // a run that never reached upgrader_process_complete
        add_action('admin_init', [__CLASS__, 'ensure_log']); // log_since = when this Connector version first ran
    }

    public function register_routes() {
        register_rest_route('evive/v1', '/updates', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_updates'],
            'permission_callback' => function () { return current_user_can('edit_posts'); },
            'args'                => [
                'since' => ['default' => '', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);
    }

    // ── Storage ─────────────────────────────────────────────────────────────

    private static function read_log() {
        $log = is_multisite() ? get_site_option(self::OPTION) : get_option(self::OPTION);
        return (is_array($log) && isset($log['entries']) && is_array($log['entries'])) ? $log : null;
    }

    private static function save_log(array $log) {
        if (is_multisite()) {
            update_site_option(self::OPTION, $log); // network options are never autoloaded
        } elseif (get_option(self::OPTION) === false) {
            add_option(self::OPTION, $log, '', 'no');
        } else {
            update_option(self::OPTION, $log, false);
        }
    }

    public static function ensure_log() {
        $log = self::read_log();
        if ($log) return $log;
        $log = ['since' => gmdate('c'), 'version' => defined('EVIVE_VERSION') ? EVIVE_VERSION : null, 'entries' => []];
        self::save_log($log);
        return $log;
    }

    // ── Reading plugin and theme headers from the files (no caches: they change during the run) ──

    private static function plugin_slug($file) {
        $dir = dirname($file);
        return $dir === '.' ? basename($file, '.php') : $dir;
    }

    /** A plugin file relative to the plugins folder ("akismet/akismet.php") -> [name, version] or null. */
    private static function plugin_header($file) {
        $path = WP_PLUGIN_DIR . '/' . $file;
        if (!$file || !is_file($path)) return null;
        $h = get_file_data($path, ['name' => 'Plugin Name', 'version' => 'Version'], 'plugin');
        return $h['name'] !== '' ? ['name' => $h['name'], 'version' => $h['version'] !== '' ? $h['version'] : null] : null;
    }

    /** The main file of the plugin in a folder of the plugins folder, as get_plugins() finds it, or null. */
    private static function plugin_file_in($folder) {
        if ($folder === '' || !is_dir(WP_PLUGIN_DIR . '/' . $folder)) return null;
        foreach (glob(WP_PLUGIN_DIR . '/' . $folder . '/*.php') ?: [] as $path) {
            $file = $folder . '/' . basename($path);
            if (self::plugin_header($file)) return $file;
        }
        return null;
    }

    private static function theme_header($slug) {
        if ($slug === '') return null;
        $path = get_theme_root($slug) . '/' . $slug . '/style.css';
        if (!is_file($path)) return null;
        $h = get_file_data($path, ['name' => 'Theme Name', 'version' => 'Version'], 'theme');
        return $h['name'] !== '' ? ['name' => $h['name'], 'version' => $h['version'] !== '' ? $h['version'] : null] : null;
    }

    private static function core_version_on_disk() {
        $src = @file_get_contents(ABSPATH . WPINC . '/version.php');
        return ($src && preg_match('/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/', $src, $m)) ? $m[1] : null;
    }

    /** 'plugin', 'theme' or null (core, translations: not via this path). */
    private static function run_type($upgrader, $hook_extra) {
        if (class_exists('Plugin_Upgrader') && $upgrader instanceof Plugin_Upgrader) return 'plugin';
        if (class_exists('Theme_Upgrader') && $upgrader instanceof Theme_Upgrader) return 'theme';
        if (!empty($hook_extra['plugin'])) return 'plugin';
        if (!empty($hook_extra['theme'])) return 'theme';
        $t = $hook_extra['type'] ?? null;
        return in_array($t, ['plugin', 'theme'], true) ? $t : null;
    }

    /** The slug of the item in this package: from hook_extra on an update, from the package folder on an install. */
    private static function item_slug($type, $hook_extra, $folder) {
        if ($type === 'plugin') return !empty($hook_extra['plugin']) ? self::plugin_slug($hook_extra['plugin']) : $folder;
        return !empty($hook_extra['theme']) ? (string) $hook_extra['theme'] : $folder;
    }

    // ── Hooks ───────────────────────────────────────────────────────────────

    /** upgrader_source_selection: the new package is unpacked, the old files are still in place. Filter: returns $source. */
    public static function remember_before($source, $remote_source = null, $upgrader = null, $hook_extra = []) {
        try {
            if (is_wp_error($source) || !is_string($source)) return $source;
            $type = self::run_type($upgrader, (array) $hook_extra);
            if (!$type) return $source;
            $folder = basename(untrailingslashit($source));
            $slug   = self::item_slug($type, (array) $hook_extra, $folder);
            if ($type === 'plugin') {
                $file = !empty($hook_extra['plugin']) ? $hook_extra['plugin'] : self::plugin_file_in($folder);
                self::$before["plugin:$slug"] = $file ? self::plugin_header($file) : null;
            } else {
                self::$before["theme:$slug"] = self::theme_header($slug);
            }
        } catch (\Throwable $e) {
            // Never break an update because of the log.
        }
        return $source;
    }

    /** upgrader_install_package_result: one package (one plugin or theme) was put in place. Filter: returns $result. */
    public static function remember_done($result, $hook_extra = []) {
        try {
            if (is_wp_error($result) || !is_array($result) || empty($result['destination_name'])) return $result;
            $hook_extra = (array) $hook_extra;
            $type = !empty($hook_extra['plugin']) ? 'plugin' : (!empty($hook_extra['theme']) ? 'theme' : ($hook_extra['type'] ?? null));
            if (!in_array($type, ['plugin', 'theme'], true)) return $result;
            $folder = (string) $result['destination_name'];
            $slug   = self::item_slug($type, $hook_extra, $folder);
            $file   = null;
            if ($type === 'plugin') $file = !empty($hook_extra['plugin']) ? $hook_extra['plugin'] : self::plugin_file_in($folder);
            self::$done["$type:$slug"] = ['type' => $type, 'slug' => $slug, 'file' => $file];
        } catch (\Throwable $e) {
        }
        return $result;
    }

    /** upgrader_process_complete: write what this run put in place. */
    public static function log_run($upgrader = null, $hook_extra = []) {
        try {
            $hook_extra = (array) $hook_extra;
            if (($hook_extra['type'] ?? null) === 'core') {
                $to = self::core_version_on_disk();
                if ($to) self::append([['type' => 'core', 'slug' => 'wordpress', 'name' => 'WordPress', 'from' => self::$core_before, 'to' => $to, 'time' => gmdate('c')]]);
                self::$core_before = $to ?: self::$core_before;
                return;
            }
            self::flush();
        } catch (\Throwable $e) {
        }
    }

    /** Turn the finished items into log entries ("to" read from the new files). */
    public static function flush() {
        if (!self::$done) return;
        $done = self::$done;
        self::$done = [];
        $entries = [];
        foreach ($done as $key => $item) {
            $after = $item['type'] === 'plugin' ? self::plugin_header((string) $item['file']) : self::theme_header($item['slug']);
            if (!$after) continue;
            $before = self::$before[$key] ?? null;
            unset(self::$before[$key]);
            $entries[] = [
                'type' => $item['type'],
                'slug' => $item['slug'],
                'name' => $after['name'],
                'from' => $before['version'] ?? null,
                'to'   => $after['version'],
                'time' => gmdate('c'),
            ];
        }
        if ($entries) self::append($entries);
    }

    private static function append(array $entries) {
        $log = self::ensure_log();
        $log['entries'] = array_slice(array_merge($log['entries'], $entries), -self::MAX);
        self::save_log($log);
    }

    // ── REST ────────────────────────────────────────────────────────────────

    public function get_updates(WP_REST_Request $request) {
        $since    = trim((string) $request->get_param('since'));
        $since_ts = null;
        if ($since !== '') {
            $since_ts = strtotime($since);
            if ($since_ts === false) {
                return new WP_Error('invalid_since', "Invalid since '{$since}'. Use an ISO 8601 date, e.g. '2026-11-01' or '2026-11-01T00:00:00Z'.", ['status' => 400]);
            }
        }
        $log = self::ensure_log();

        $installed = [];
        foreach (array_reverse($log['entries']) as $e) { // newest first
            if ($since_ts !== null && strtotime($e['time'] ?? '') < $since_ts) continue;
            $installed[] = $e;
        }

        return rest_ensure_response([
            'installed'   => $installed,
            'waiting'     => self::waiting(),
            'log_since'   => $log['since'],
            'log_version' => $log['version'],
        ]);
    }

    /** What WordPress last found to update (site transients; no new check is forced). */
    private static function waiting() {
        if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $items = [];
        $count = ['core' => 0, 'plugins' => 0, 'themes' => 0];

        $core = get_site_transient('update_core');
        if (is_object($core) && !empty($core->updates) && is_array($core->updates)) {
            foreach ($core->updates as $u) {
                if (is_object($u) && ($u->response ?? '') === 'upgrade' && !empty($u->version)) {
                    $items[] = ['type' => 'core', 'slug' => 'wordpress', 'name' => 'WordPress',
                                'current' => $GLOBALS['wp_version'] ?? null, 'new' => (string) $u->version];
                    $count['core'] = 1;
                    break; // one offer per site (the transient repeats it per locale)
                }
            }
        }

        $plugins = get_site_transient('update_plugins');
        if (is_object($plugins) && !empty($plugins->response) && is_array($plugins->response)) {
            $all = get_plugins();
            foreach ($plugins->response as $file => $u) {
                if (!isset($all[$file])) continue;
                $u = (object) $u;
                $items[] = ['type' => 'plugin', 'slug' => self::plugin_slug($file), 'name' => $all[$file]['Name'],
                            'current' => $all[$file]['Version'] ?: null, 'new' => isset($u->new_version) ? (string) $u->new_version : null];
                $count['plugins']++;
            }
        }

        $themes = get_site_transient('update_themes');
        if (is_object($themes) && !empty($themes->response) && is_array($themes->response)) {
            foreach ($themes->response as $slug => $u) {
                $theme = wp_get_theme($slug);
                if (!$theme->exists()) continue;
                $u = (array) $u;
                $items[] = ['type' => 'theme', 'slug' => (string) $slug, 'name' => $theme->get('Name'),
                            'current' => $theme->get('Version') ?: null, 'new' => isset($u['new_version']) ? (string) $u['new_version'] : null];
                $count['themes']++;
            }
        }

        return $count + ['items' => $items];
    }
}

Evive_Updates::init();
