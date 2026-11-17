<?php
/**
 * Recurring schedules for sources and system tasks.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Scheduling;

use MicroStudio\MicroSyncPro\Plugin;
use MicroStudio\MicroSyncPro\Sources\Source;
use MicroStudio\MicroSyncPro\Sync\Run;

defined( 'ABSPATH' ) || exit;

/**
 * Registers per-source recurring synchronizations plus the watchdog and
 * retention tasks, using Action Scheduler when available and WP-Cron otherwise.
 */
class Scheduler {

	const HOOK_SOURCE    = 'msp_scheduled_sync';
	const HOOK_RETENTION = 'msp_retention';
	const SYSTEM_OPTION  = 'msp_system_events';
	const WATCHDOG_EVERY = 600;

	/** @var Plugin */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Interval in seconds for each schedule key.
	 *
	 * @return array<string,int>
	 */
	public static function intervals(): array {
		return array(
			'hourly'     => HOUR_IN_SECONDS,
			'twicedaily' => 12 * HOUR_IN_SECONDS,
			'daily'      => DAY_IN_SECONDS,
			'weekly'     => WEEK_IN_SECONDS,
		);
	}

	/**
	 * Labels.
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		return array(
			'none'       => __( 'Disabled', 'micro-sync-pro' ),
			'hourly'     => __( 'Every hour', 'micro-sync-pro' ),
			'twicedaily' => __( 'Twice a day', 'micro-sync-pro' ),
			'daily'      => __( 'Once a day', 'micro-sync-pro' ),
			'weekly'     => __( 'Once a week', 'micro-sync-pro' ),
		);
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- 10 minutes is intentional for the watchdog.
		add_action( self::HOOK_SOURCE, array( $this, 'run_scheduled' ), 10, 1 );
		add_action( self::HOOK_RETENTION, array( $this->plugin->retention(), 'run' ) );
		add_action( 'admin_init', array( $this, 'ensure_system_events' ) );
		add_action( 'action_scheduler_init', array( $this, 'maybe_after_activation' ) );
		add_action( 'init', array( $this, 'maybe_after_activation' ), 30 );
	}

	/**
	 * Add a 10-minute cron schedule (fallback for the watchdog).
	 *
	 * @param array<string,array<string,mixed>> $schedules Schedules.
	 * @return array<string,array<string,mixed>>
	 */
	public function cron_schedules( $schedules ) {
		$schedules['msp_ten_minutes'] = array(
			'interval' => self::WATCHDOG_EVERY,
			// Schedules can be read before "init" (e.g. by other plugins); avoid early translation loading.
			'display'  => did_action( 'init' ) ? __( 'Every 10 minutes (Micro Sync Pro)', 'micro-sync-pro' ) : 'Every 10 minutes (Micro Sync Pro)',
		);
		return $schedules;
	}

	/**
	 * After activation, (re)create system events and source schedules once.
	 */
	public function maybe_after_activation(): void {
		if ( ! get_option( 'msp_needs_schedule_sync' ) ) {
			return;
		}
		if ( function_exists( 'as_schedule_recurring_action' ) && ! did_action( 'action_scheduler_init' ) ) {
			return; // Wait for Action Scheduler.
		}
		delete_option( 'msp_needs_schedule_sync' );
		$this->ensure_system_events( true );
		foreach ( $this->plugin->sources()->all() as $source ) {
			$this->sync_source( $source );
		}
	}

	/**
	 * Ensure the watchdog and retention tasks are scheduled.
	 *
	 * @param bool $force Re-check even if the option says done.
	 */
	public function ensure_system_events( $force = false ): void {
		$mode = JobQueue::use_action_scheduler() ? 'as' : 'cron';
		if ( ! $force && get_option( self::SYSTEM_OPTION ) === $mode && $this->next( JobQueue::HOOK_WATCHDOG, array() ) ) {
			return;
		}
		if ( 'as' === $mode ) {
			if ( ! as_has_scheduled_action( JobQueue::HOOK_WATCHDOG, array(), JobQueue::GROUP ) ) {
				as_schedule_recurring_action( time() + self::WATCHDOG_EVERY, self::WATCHDOG_EVERY, JobQueue::HOOK_WATCHDOG, array(), JobQueue::GROUP );
			}
			if ( ! as_has_scheduled_action( self::HOOK_RETENTION, array(), JobQueue::GROUP ) ) {
				as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK_RETENTION, array(), JobQueue::GROUP );
			}
			wp_clear_scheduled_hook( JobQueue::HOOK_WATCHDOG );
			wp_clear_scheduled_hook( self::HOOK_RETENTION );
		} else {
			if ( ! wp_next_scheduled( JobQueue::HOOK_WATCHDOG ) ) {
				wp_schedule_event( time() + self::WATCHDOG_EVERY, 'msp_ten_minutes', JobQueue::HOOK_WATCHDOG );
			}
			if ( ! wp_next_scheduled( self::HOOK_RETENTION ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK_RETENTION );
			}
		}
		update_option( self::SYSTEM_OPTION, $mode, false );
	}

	/**
	 * Apply a source's schedule (unschedules first).
	 *
	 * @param Source $source Source.
	 */
	public function sync_source( Source $source ): void {
		$this->unschedule_source( $source->id );
		$intervals = self::intervals();
		if ( 'active' !== $source->status || ! isset( $intervals[ $source->schedule ] ) ) {
			return;
		}
		$interval = $intervals[ $source->schedule ];
		$args     = array( $source->id );
		if ( JobQueue::use_action_scheduler() ) {
			as_schedule_recurring_action( time() + $interval, $interval, self::HOOK_SOURCE, $args, JobQueue::GROUP );
		} else {
			wp_schedule_event( time() + $interval, $source->schedule, self::HOOK_SOURCE, $args );
		}
	}

	/**
	 * Remove a source's schedule from both backends.
	 *
	 * @param int $source_id Source.
	 */
	public function unschedule_source( int $source_id ): void {
		$args = array( $source_id );
		if ( function_exists( 'as_unschedule_all_actions' ) && did_action( 'action_scheduler_init' ) ) {
			as_unschedule_all_actions( self::HOOK_SOURCE, $args, JobQueue::GROUP );
		}
		wp_clear_scheduled_hook( self::HOOK_SOURCE, $args );
	}

	/**
	 * Next run timestamp for a source (or 0).
	 *
	 * @param int $source_id Source.
	 */
	public function next_for_source( int $source_id ): int {
		return $this->next( self::HOOK_SOURCE, array( $source_id ) );
	}

	/**
	 * Next timestamp of a hook in either backend.
	 *
	 * @param string    $hook Hook.
	 * @param array<int> $args Args.
	 */
	private function next( string $hook, array $args ): int {
		if ( function_exists( 'as_next_scheduled_action' ) && did_action( 'action_scheduler_init' ) ) {
			$t = as_next_scheduled_action( $hook, $args, JobQueue::GROUP );
			if ( is_int( $t ) && $t > 0 ) {
				return $t;
			}
			if ( true === $t ) {
				return time();
			}
		}
		$t = wp_next_scheduled( $hook, $args );
		return $t ? (int) $t : 0;
	}

	/**
	 * Scheduled callback: start an apply run for the source.
	 *
	 * @param int|string $source_id Source.
	 */
	public function run_scheduled( $source_id ): void {
		$source_id = (int) $source_id;
		$source    = $this->plugin->sources()->find( $source_id );
		if ( ! $source || 'active' !== $source->status || 'none' === $source->schedule ) {
			$this->unschedule_source( $source_id );
			return;
		}
		$run = $this->plugin->run_manager()->start( $source_id, Run::MODE_APPLY, 'schedule', 0 );
		if ( is_wp_error( $run ) ) {
			$this->plugin->logger()->warning( 'Scheduled synchronization was not started.', array( 'source_id' => $source_id, 'reason' => $run->get_error_message() ) );
		}
	}

	/**
	 * Remove every scheduled event of the plugin (deactivation).
	 */
	public static function unschedule_everything(): void {
		$hooks = array( self::HOOK_SOURCE, self::HOOK_RETENTION, JobQueue::HOOK_WATCHDOG, JobQueue::HOOK_PROCESS );
		foreach ( $hooks as $hook ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook );
			}
			wp_unschedule_hook( $hook );
		}
		delete_option( self::SYSTEM_OPTION );
	}
}
