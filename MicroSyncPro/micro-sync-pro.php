<?php
/**
 * Plugin Name:       Micro Sync Pro
 * Plugin URI:        https://example.com/micro-sync-pro
 * Description:       Professional WooCommerce product price and inventory synchronization from CSV, XLSX, and remote data sources.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Micro Studio
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       micro-sync-pro
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.5
 * WC tested up to:   11.3
 *
 * @package MicroStudio\MicroSyncPro
 */

defined( 'ABSPATH' ) || exit;

define( 'MSP_VERSION', '1.0.0' );
define( 'MSP_FILE', __FILE__ );
define( 'MSP_DIR', plugin_dir_path( __FILE__ ) );
define( 'MSP_URL', plugin_dir_url( __FILE__ ) );
define( 'MSP_MIN_PHP', '7.4' );
define( 'MSP_MIN_WC', '8.5' );

if ( version_compare( PHP_VERSION, MSP_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>' . esc_html(
				sprintf(
					/* translators: %s: required PHP version. */
					__( 'Micro Sync Pro requires PHP %s or newer. The plugin is not running.', 'micro-sync-pro' ),
					MSP_MIN_PHP
				)
			) . '</p></div>';
		}
	);
	return;
}

require_once MSP_DIR . 'src/Autoloader.php';
\MicroStudio\MicroSyncPro\Autoloader::register();

register_activation_hook( __FILE__, array( \MicroStudio\MicroSyncPro\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \MicroStudio\MicroSyncPro\Deactivator::class, 'deactivate' ) );

// Declare WooCommerce feature compatibility (HPOS, cart/checkout blocks).
add_action( 'before_woocommerce_init', array( \MicroStudio\MicroSyncPro\Compatibility::class, 'declare_features' ) );

// The license file and the protected plugin files are ionCube-encoded. Without the
// ionCube Loader, loading any of them prints an error and stops the request, which
// would take the whole site down. In that case none of them is loaded: the plugin
// stays inactive and only shows an admin notice.
if ( ! extension_loaded( 'ionCube Loader' ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			load_plugin_textdomain( 'micro-sync-pro', false, dirname( plugin_basename( MSP_FILE ) ) . '/languages' );
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Micro Sync Pro', 'micro-sync-pro' ) . ':</strong> '
				. esc_html__( 'Micro Sync Pro needs the ionCube Loader PHP extension to verify its license. Ask your host to enable ionCube Loader for your PHP version. The plugin is inactive until then.', 'micro-sync-pro' )
				. '</p></div>';
		}
	);
	return;
}

// RTL-Theme license loader. This file is NOT protected (it contains the loader).
$rtlLicenseFilePath = __DIR__ . DIRECTORY_SEPARATOR . 'RTL_License_1f777b937ec5d599.php'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName

if ( is_file( $rtlLicenseFilePath ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
	require_once $rtlLicenseFilePath; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
}

// License check (protected file): unlocks the plugin when the license is active.
require_once __DIR__ . '/license.php';

add_action( 'plugins_loaded', array( \MicroStudio\MicroSyncPro\Plugin::instance(), 'boot' ), 20 );
