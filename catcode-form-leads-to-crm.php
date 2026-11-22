<?php
/**
 * Plugin Name: CatCode Form Leads to CRM
 * Plugin URI: https://catcode.com.ua/
 * Description: Sends leads from your site forms to KeyCRM, SalesDrive, NetHunt, KeepinCRM and Telegram. No WooCommerce needed. Every lead is logged and retried if the CRM does not answer.
 * Version: 1.0.2
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: CatCode
 * Author URI: https://catcode.com.ua
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: catcode-form-leads-to-crm
 * Domain Path: /languages
 *
 * @package CatCodeFormLeads
 */

defined( 'ABSPATH' ) || exit;

// The copy from wordpress.org and the one from catcode.com.ua share their class
// names. When both are active, the one that loads second steps aside with a
// notice instead of a fatal "cannot redeclare" error.
if ( defined( 'CCFLC_FILE' ) ) {
	if ( CCFLC_FILE !== __FILE__ ) {
		add_action(
			'admin_notices',
			static function () {
				if ( current_user_can( 'activate_plugins' ) ) {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Two copies of Form Leads to CRM are active. Keep one of them: deactivate the other copy on the Plugins screen.', 'catcode-form-leads-to-crm' ) . '</p></div>';
				}
			}
		);
	}
	return;
}


define( 'CCFLC_VERSION', '1.0.2' );
define( 'CCFLC_FILE', __FILE__ );
define( 'CCFLC_DIR', plugin_dir_path( __FILE__ ) );
define( 'CCFLC_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'CatCode\\FormLeads\\';
		if ( 0 !== strncmp( $class, $prefix, strlen( $prefix ) ) ) {
			return;
		}
		$path = CCFLC_DIR . 'includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);


register_activation_hook( __FILE__, array( '\\CatCode\\FormLeads\\Core\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\\CatCode\\FormLeads\\Core\\Installer', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		\CatCode\FormLeads\Core\Plugin::boot();
	}
);
