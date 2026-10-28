<?php
/**
 * Plugin Name: evive Connector
 * Plugin URI:  https://evive.works
 * Description: Connects WordPress to evive AI for full Elementor and custom post type support.
 * Version:     1.3.30
 * Author:      StackPros B.V.
 * License:     Proprietary
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Update URI:  https://github.com/evive-works/evive-connector
 */

if (!defined('ABSPATH')) exit;

define('EVIVE_VERSION', '1.3.30');
define('EVIVE_PATH', plugin_dir_path(__FILE__));

// ── Auto-update from GitHub releases via Plugin Update Checker ────────────
// Since 1.3.26: the release asset evive-connector.zip of github.com/evive-works/evive-connector.
// Up to 1.3.25 updates came from a JSON manifest on app.evive.works.
require_once __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$eviveUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/evive-works/evive-connector/',
    __FILE__,
    'evive-connector'
);
$eviveUpdateChecker->setBranch('main');
$eviveUpdateChecker->getVcsApi()->enableReleaseAssets('/evive-connector\.zip$/');

// Permission checks for routes beyond content editing (evive #373). Return a WP_Error with a clear
// message, so evive can tell the user why instead of a bare "forbidden".
// Site settings, backups status and caches: a site administrator.
function evive_can_manage_site() {
    if (current_user_can('manage_options')) return true;
    return new WP_Error('evive_forbidden', 'evive needs a WordPress administrator account for this.', ['status' => 403]);
}
// Server files (wp-config.php, .htaccess), PHP OPcache and deleting backups: on a single site an administrator;
// on Multisite these are shared by the whole network, so only a super admin.
function evive_can_change_server() {
    if (is_multisite()) {
        if (is_super_admin()) return true;
        return new WP_Error('evive_forbidden', 'On a Multisite network this needs a network (super) admin: it affects every site in the network.', ['status' => 403]);
    }
    return evive_can_manage_site();
}

require_once EVIVE_PATH . 'includes/class-meta.php';
require_once EVIVE_PATH . 'includes/class-content-map.php';
require_once EVIVE_PATH . 'includes/class-elementor.php';
require_once EVIVE_PATH . 'includes/class-clone.php';
require_once EVIVE_PATH . 'includes/class-backup.php';
require_once EVIVE_PATH . 'includes/class-revslider.php';
require_once EVIVE_PATH . 'includes/class-page-images.php';
require_once EVIVE_PATH . 'includes/class-site-profile.php';
require_once EVIVE_PATH . 'includes/class-preview-token.php';
require_once EVIVE_PATH . 'includes/class-seo.php';
require_once EVIVE_PATH . 'includes/class-update.php';
require_once EVIVE_PATH . 'includes/class-structure.php';
require_once EVIVE_PATH . 'includes/class-kit.php';
require_once EVIVE_PATH . 'includes/class-seo-compat.php';
require_once EVIVE_PATH . 'includes/class-faq-schema.php';
require_once EVIVE_PATH . 'includes/class-cf7-form.php';
require_once EVIVE_PATH . 'includes/class-items.php';

add_action('rest_api_init', function () {
    (new Evive_Content_Map())->register_routes();
    (new Evive_Elementor())->register_routes();
    (new Evive_Clone())->register_routes();
    (new Evive_Backup())->register_routes();
    (new EVIVE_RevSlider())->register_routes();
    (new Evive_Page_Images())->register_routes();
    (new Evive_Site_Profile())->register_routes();
    (new Evive_Preview_Token())->register_routes();
    (new Evive_Seo())->register_routes();
    (new Evive_Update())->register_routes();
    (new Evive_Structure())->register_routes();
    (new Evive_Kit())->register_routes();
    (new Evive_Faq_Schema())->register_routes();
    (new Evive_Cf7_Form())->register_routes();
    (new Evive_Items())->register_routes();
});

add_action('init', 'evive_register_meta');

// Fix English month names on Dutch portfolio single pages.
add_action('wp_footer', function () {
    if (!is_singular('portfolio')) return;
    if (function_exists('pll_current_language') && pll_current_language() !== 'nl') return;
    echo '<script>
(function(){
  var sel = ".entry-date time, .portfolio-date time";
  document.querySelectorAll(sel).forEach(function(t){
    var iso = t.getAttribute("datetime");
    if (!iso) return;
    var d = new Date(iso);
    if (isNaN(d.getTime())) return;
    t.textContent = d.toLocaleDateString("nl-NL", {day:"numeric", month:"long", year:"numeric"});
  });
})();
</script>';
}, 20);

// Fix English month names on English portfolio pages.
add_action('wp_footer', function () {
    if (!is_singular('portfolio')) return;
    $lang = function_exists('pll_current_language') ? pll_current_language() : 'en';
    if ($lang !== 'en') return;
    echo '<script>
(function(){
  var sel = ".entry-date time, .portfolio-date time";
  document.querySelectorAll(sel).forEach(function(t){
    var iso = t.getAttribute("datetime");
    if (!iso) return;
    var d = new Date(iso);
    if (isNaN(d.getTime())) return;
    t.textContent = d.toLocaleDateString("en-GB", {day:"numeric", month:"long", year:"numeric"});
  });
})();
</script>';
}, 20);
