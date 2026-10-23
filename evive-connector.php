<?php
/**
 * Plugin Name: evive Connector
 * Plugin URI:  https://evive.works
 * Description: Connects WordPress to evive AI for full Elementor and custom post type support.
 * Version:     1.3.26
 * Author:      StackPros B.V.
 * License:     Proprietary
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Update URI:  https://github.com/evive-works/evive-connector
 */

if (!defined('ABSPATH')) exit;

define('EVIVE_VERSION', '1.3.26');
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
