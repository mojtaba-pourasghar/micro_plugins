<?php
/**
 * PHPUnit bootstrap: loads the WordPress test library, WooCommerce and the plugin.
 *
 * Required environment variables:
 *   WP_CORE_DIR  WordPress core directory.
 *   WC_DIR       WooCommerce plugin directory.
 *   MSP_HPOS     Optional "1" to run with HPOS (custom order tables) enabled.
 *
 * @package MicroStudio\MicroSyncPro\Tests
 */

$msp_root = dirname( __DIR__ );
require_once $msp_root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
$msp_tests_dir = $msp_root . '/vendor/wp-phpunit/wp-phpunit';
require_once $msp_tests_dir . '/includes/functions.php';

$msp_wc_dir = rtrim( (string) getenv( 'WC_DIR' ), '/' );
if ( '' === $msp_wc_dir || ! is_file( $msp_wc_dir . '/woocommerce.php' ) ) {
	fwrite( STDERR, "Set WC_DIR to the WooCommerce plugin directory.\n" );
	exit( 1 );
}

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $msp_wc_dir, $msp_root ) {
		update_option( 'woocommerce_custom_orders_table_enabled', '1' === getenv( 'MSP_HPOS' ) ? 'yes' : 'no' );
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
		require $msp_wc_dir . '/woocommerce.php';
		// Load the plugin from the plugins directory when it is installed there (as in production),
		// so WordPress/WooCommerce identify it by its real plugin basename.
		$installed = WP_PLUGIN_DIR . '/micro-sync-pro/micro-sync-pro.php';
		if ( is_file( $installed ) ) {
			wp_register_plugin_realpath( $installed );
			update_option( 'active_plugins', array( 'woocommerce/woocommerce.php', 'micro-sync-pro/micro-sync-pro.php' ) );
			require $installed;
		} else {
			require $msp_root . '/micro-sync-pro.php';
		}
	}
);

tests_add_filter(
	'setup_theme',
	static function () {
		if ( '1' === getenv( 'MSP_HPOS' ) ) {
			// Create the HPOS tables before the installer queries orders.
			wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class )->create_database_tables();
		}
		\WC_Install::install();
		\MicroStudio\MicroSyncPro\Database\Migrator::migrate();
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_roles();
	}
);

require $msp_tests_dir . '/includes/bootstrap.php';
require_once __DIR__ . '/TestCase.php';
