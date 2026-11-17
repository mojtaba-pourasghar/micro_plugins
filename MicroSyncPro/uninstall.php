<?php
/**
 * Uninstall routine. Data is removed only when the site owner enabled
 * "Delete all plugin data" in the settings.
 *
 * @package MicroStudio\MicroSyncPro
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$msp_settings = get_option( 'msp_settings', array() );
if ( ! is_array( $msp_settings ) || empty( $msp_settings['delete_data_on_uninstall'] ) ) {
	return;
}

require_once __DIR__ . '/src/Autoloader.php';
\MicroStudio\MicroSyncPro\Autoloader::register();

\MicroStudio\MicroSyncPro\Scheduling\Scheduler::unschedule_everything();
\MicroStudio\MicroSyncPro\Database\Migrator::drop_tables();

// Remove stored files.
$msp_dir = ( new \MicroStudio\MicroSyncPro\Sources\Storage() )->base_dir();
if ( is_dir( $msp_dir ) ) {
	foreach ( (array) scandir( $msp_dir ) as $msp_file ) {
		if ( is_string( $msp_file ) && '.' !== $msp_file && '..' !== $msp_file && is_file( $msp_dir . '/' . $msp_file ) ) {
			wp_delete_file( $msp_dir . '/' . $msp_file );
		}
	}
	@rmdir( $msp_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

foreach ( array( 'msp_settings', 'msp_db_version', 'msp_system_events', 'msp_needs_schedule_sync' ) as $msp_option ) {
	delete_option( $msp_option );
}
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_msp\_%' OR option_name LIKE '\_transient\_timeout\_msp\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
