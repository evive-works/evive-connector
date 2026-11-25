<?php
/**
 * evive #510: the evive-language plugin (0.1.1) is now part of the evive Connector: translation links between the
 * sites of a Multisite network (one site per language), hreflang tags, the language switcher block
 * (evive-language/switcher), the editor panel "Translations", Settings → Language and `wp evive-language`.
 *
 * Same data as evive-language (post meta `_evive_translations`, options `evive_language` and
 * `evive_language_x_default`), so links made with the plugin stay. REST: evive-language/v1/* (the old paths, so evive's
 * MCP works unchanged) and evive/v1/language/* (same routes).
 *
 * While the evive-language plugin is still active it keeps its own routes, block and hreflang; the Connector then only
 * adds evive/v1/language/* and shows a notice that the plugin can be deactivated.
 *
 * @package evive-connector
 */

defined( 'ABSPATH' ) || exit;

foreach ( [ 'class-translations', 'interface-store', 'class-linker', 'class-urls', 'class-sites', 'class-wp-store', 'class-plugin', 'class-rest', 'class-settings', 'class-cli' ] as $evive_language_file ) {
	require_once EVIVE_PATH . 'language/' . $evive_language_file . '.php';
}
unset( $evive_language_file );

/** Is the separate evive-language plugin loaded? Decided on plugins_loaded: it loads after the Connector. */
function evive_language_plugin_active(): bool {
	return defined( 'EVIVE_LANGUAGE_VERSION' ) || class_exists( 'Evive\\Language\\Plugin', false );
}

add_action( 'plugins_loaded', static function () {
	if ( evive_language_plugin_active() ) {
		// Only the Connector's own namespace: evive-language keeps evive-language/v1, its block and hreflang.
		add_action( 'rest_api_init', [ Evive\Connector\Language\Rest::class, 'register_connector_only' ] );
		add_action( 'init', static function () {
			load_plugin_textdomain( 'evive-language', false, dirname( plugin_basename( EVIVE_PATH . 'evive-connector.php' ) ) . '/language/languages' );
		} );
		add_action( 'admin_notices', 'evive_language_plugin_notice' );
		add_action( 'network_admin_notices', 'evive_language_plugin_notice' );
		return;
	}
	Evive\Connector\Language\Plugin::boot();
} );

/** "evive-language is now part of the evive Connector; you can deactivate it." For whoever can deactivate it. */
function evive_language_plugin_notice(): void {
	$network = is_network_admin();
	if ( ! current_user_can( $network ? 'manage_network_plugins' : 'activate_plugins' ) ) {
		return;
	}
	// On a site of a network where it is network-activated, only a super admin can deactivate it (in Network Admin).
	if ( ! $network && is_multisite() && evive_language_network_active( 'evive-language/evive-language.php' ) && ! current_user_can( 'manage_network_plugins' ) ) {
		return;
	}
	$url = ( $network || ( is_multisite() && evive_language_network_active( 'evive-language/evive-language.php' ) ) ) ? network_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' );
	printf(
		'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
		esc_html__( 'evive-language is now part of the evive Connector; you can deactivate it.', 'evive-language' ),
		esc_url( $url ),
		esc_html__( 'Plugins', 'evive-language' )
	);
}

/** is_plugin_active_for_network() lives in wp-admin/includes/plugin.php; load it when needed. */
function evive_language_network_active( string $plugin ): bool {
	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	return is_plugin_active_for_network( $plugin );
}
