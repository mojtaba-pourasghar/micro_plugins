<?php
/**
 * Activation routine.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro;

use MicroStudio\MicroSyncPro\Database\Migrator;
use MicroStudio\MicroSyncPro\Sources\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on plugin activation.
 */
final class Activator {

	/**
	 * Activate: create tables, storage directory and default options.
	 */
	public static function activate(): void {
		Migrator::migrate();
		( new Storage() )->ensure_directory();
		if ( false === get_option( 'msp_settings', false ) ) {
			add_option( 'msp_settings', array(), '', false );
		}
		// Watchdog/retention events are (re)registered on the next load by the Scheduler.
		update_option( 'msp_needs_schedule_sync', 1, false );
	}
}
