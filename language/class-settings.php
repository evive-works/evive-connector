<?php
/**
 * Settings: per site (Settings → Language) and per network (Network Admin → Settings → Languages).
 *
 * @package evive-connector (language, from evive-language 0.1.1; evive #510)
 */

namespace Evive\Connector\Language;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const PAGE = 'evive-language';

	public static function boot(): void {
		add_action( 'admin_menu', [ __CLASS__, 'site_menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_setting' ] );
		add_action( 'network_admin_menu', [ __CLASS__, 'network_menu' ] );
		add_action( 'network_admin_edit_' . self::PAGE, [ __CLASS__, 'save_network' ] );
	}

	public static function register_setting(): void {
		register_setting( self::PAGE, Sites::OPTION, [
			'type'              => 'array',
			'sanitize_callback' => [ __CLASS__, 'sanitize' ],
			'default'           => [],
		] );
	}

	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : [];
		return [
			'enabled'  => ! empty( $input['enabled'] ),
			'code'     => sanitize_key( $input['code'] ?? '' ),
			'label'    => sanitize_text_field( $input['label'] ?? '' ),
			'hreflang' => preg_replace( '/[^A-Za-z0-9-]/', '', (string) ( $input['hreflang'] ?? '' ) ),
		];
	}

	public static function site_menu(): void {
		add_options_page(
			__( 'Language', 'evive-language' ),
			__( 'Language', 'evive-language' ),
			'manage_options',
			self::PAGE,
			[ __CLASS__, 'render_site' ]
		);
	}

	public static function render_site(): void {
		$stored   = (array) get_option( Sites::OPTION, [] );
		$current  = Sites::settings( get_current_blog_id() );
		$defaults = Sites::defaults( $current['locale'] );
		$enabled  = array_key_exists( 'enabled', $stored ) ? (bool) $stored['enabled'] : true;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Language', 'evive-language' ); ?></h1>
			<p>
				<?php
				/* translators: %s: locale, e.g. nl_NL */
				printf( esc_html__( 'This site is in %s (Settings → General → Site Language). Leave a field empty to use the default shown.', 'evive-language' ), '<code>' . esc_html( $current['locale'] ) . '</code>' );
				?>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( self::PAGE ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Language switching', 'evive-language' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( Sites::OPTION ); ?>[enabled]" value="1" <?php checked( $enabled ); ?>> <?php esc_html_e( 'This site is one of the languages of the network', 'evive-language' ); ?></label></td>
					</tr>
					<?php
					$fields = [
						'code'     => [ __( 'Language code', 'evive-language' ), __( 'Short code shown in the language switcher, e.g. nl.', 'evive-language' ) ],
						'label'    => [ __( 'Label', 'evive-language' ), __( 'Name of the language in that language, e.g. Nederlands.', 'evive-language' ) ],
						'hreflang' => [ __( 'hreflang code', 'evive-language' ), __( 'Language (and optional region) for search engines, e.g. nl-NL or en.', 'evive-language' ) ],
					];
					foreach ( $fields as $key => [ $label, $help ] ) :
						$id = 'evive-language-' . $key;
						?>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td>
								<input type="text" class="regular-text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( Sites::OPTION . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( $stored[ $key ] ?? '' ); ?>" placeholder="<?php echo esc_attr( $defaults[ $key ] ); ?>" aria-describedby="<?php echo esc_attr( $id ); ?>-help">
								<p class="description" id="<?php echo esc_attr( $id ); ?>-help"><?php echo esc_html( $help ); ?></p>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public static function network_menu(): void {
		add_submenu_page(
			'settings.php',
			__( 'Languages', 'evive-language' ),
			__( 'Languages', 'evive-language' ),
			'manage_network_options',
			self::PAGE,
			[ __CLASS__, 'render_network' ]
		);
	}

	public static function render_network(): void {
		$x_default = (int) get_site_option( Sites::X_DEFAULT_OPTION, 0 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Languages', 'evive-language' ); ?></h1>
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'evive-language' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=' . self::PAGE ) ); ?>">
				<?php wp_nonce_field( self::PAGE . '-network' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="evive-language-x-default"><?php esc_html_e( 'International fallback (x-default)', 'evive-language' ); ?></label></th>
						<td>
							<select id="evive-language-x-default" name="x_default" aria-describedby="evive-language-x-default-help">
								<option value="0"><?php esc_html_e( '— None —', 'evive-language' ); ?></option>
								<?php foreach ( Sites::all() as $blog_id => $site ) : ?>
									<option value="<?php echo esc_attr( (string) $blog_id ); ?>" <?php selected( $x_default, $blog_id ); ?>><?php echo esc_html( $site['label'] . ' — ' . $site['home'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description" id="evive-language-x-default-help"><?php esc_html_e( 'Search engines send visitors whose language has no site of its own to this site.', 'evive-language' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<h2><?php esc_html_e( 'Sites', 'evive-language' ); ?></h2>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Site', 'evive-language' ); ?></th>
					<th><?php esc_html_e( 'Code', 'evive-language' ); ?></th>
					<th><?php esc_html_e( 'Label', 'evive-language' ); ?></th>
					<th><?php esc_html_e( 'hreflang', 'evive-language' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( Sites::all() as $site ) : ?>
					<tr><td><?php echo esc_html( $site['home'] ); ?></td><td><?php echo esc_html( $site['code'] ); ?></td><td><?php echo esc_html( $site['label'] ); ?></td><td><?php echo esc_html( $site['hreflang'] ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public static function save_network(): void {
		check_admin_referer( self::PAGE . '-network' );
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'evive-language' ), 403 );
		}
		update_site_option( Sites::X_DEFAULT_OPTION, absint( $_POST['x_default'] ?? 0 ) );
		wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE, 'updated' => 1 ], network_admin_url( 'settings.php' ) ) );
		exit;
	}
}
