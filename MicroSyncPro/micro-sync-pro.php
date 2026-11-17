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

add_action( 'plugins_loaded', array( \MicroStudio\MicroSyncPro\Plugin::instance(), 'boot' ), 20 );
