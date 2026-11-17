<?php
/**
 * Deactivation routine.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro;

use MicroStudio\MicroSyncPro\Scheduling\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on plugin deactivation. Data is kept; only scheduled events are removed.
 */
final class Deactivator {

	/**
	 * Deactivate.
	 */
	public static function deactivate(): void {
		Scheduler::unschedule_everything();
	}
}
