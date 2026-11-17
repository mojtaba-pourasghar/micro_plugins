<?php
/**
 * Background job queue (Action Scheduler with WP-Cron fallback).
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Scheduling;

use MicroStudio\MicroSyncPro\Plugin;
use MicroStudio\MicroSyncPro\Sync\Run;

defined( 'ABSPATH' ) || exit;

/**
 * Processes runs in time-boxed slices. Each slice claims an atomic lock on the
 * run row, so a run is never processed by two workers at the same time (cron,
 * Action Scheduler and the admin progress screen may all try).
 */
class JobQueue {

	const HOOK_PROCESS  = 'msp_process_run';
	const HOOK_WATCHDOG = 'msp_watchdog';
	const GROUP         = 'micro-sync-pro';

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
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_action( self::HOOK_PROCESS, array( $this, 'handle' ), 10, 1 );
		add_action( self::HOOK_WATCHDOG, array( $this, 'watchdog' ) );
	}

	/**
	 * Whether Action Scheduler can be used right now.
	 */
	public static function use_action_scheduler(): bool {
		/**
		 * Filter whether Action Scheduler should be used (WP-Cron otherwise).
		 *
		 * @param bool $use Use Action Scheduler.
		 */
		return (bool) apply_filters(
			'micro_sync_pro_use_action_scheduler',
			function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_has_scheduled_action' ) && did_action( 'action_scheduler_init' ) > 0
		);
	}

	/**
	 * Queue processing of a run.
	 *
	 * @param int $run_id Run.
	 */
	public function enqueue( int $run_id ): void {
		$args = array( $run_id );
		if ( self::use_action_scheduler() ) {
			if ( ! as_has_scheduled_action( self::HOOK_PROCESS, $args, self::GROUP ) ) {
				as_enqueue_async_action( self::HOOK_PROCESS, $args, self::GROUP );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK_PROCESS, $args ) ) {
			wp_schedule_single_event( time(), self::HOOK_PROCESS, $args );
		}
		if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
			if ( function_exists( 'spawn_cron' ) && ! wp_doing_cron() ) {
				spawn_cron();
			}
		}
	}

	/**
	 * Background handler.
	 *
	 * @param int|string $run_id Run.
	 */
	public function handle( $run_id ): void {
		$run = $this->work( (int) $run_id, (int) $this->plugin->settings()->get( 'time_limit' ) );
		if ( $run && $run->is_active() ) {
			// Not finished in this slice: continue in a fresh request.
			$this->enqueue( $run->id );
		}
	}

	/**
	 * Process a run for at most $seconds. Returns null when the lock is held elsewhere.
	 *
	 * @param int $run_id  Run.
	 * @param int $seconds Time budget.
	 */
	public function work( int $run_id, int $seconds ): ?Run {
		$runs = $this->plugin->runs();
		$run  = $runs->find( $run_id );
		if ( ! $run || ! $run->is_active() ) {
			return $run;
		}
		$ttl   = max( 60, $seconds + 60 );
		$token = wp_generate_password( 32, false );
		if ( ! $runs->claim_lock( $run_id, $token, $ttl ) ) {
			return null;
		}
		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			@set_time_limit( $seconds + 30 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		try {
			$run = $this->plugin->engine()->step(
				$runs->find( $run_id ),
				microtime( true ) + $seconds,
				static function () use ( $runs, $run_id, $token, $ttl ) {
					$runs->touch_lock( $run_id, $token, $ttl );
				}
			);
		} finally {
			$runs->release_lock( $run_id, $token );
		}
		return $run;
	}

	/**
	 * Detect interrupted runs: resume them a limited number of times, then mark them interrupted.
	 */
	public function watchdog(): void {
		$settings = $this->plugin->settings();
		$runs     = $this->plugin->runs();
		$logger   = $this->plugin->logger();
		$max      = (int) $settings->get( 'max_resume_attempts' );
		foreach ( $runs->stale( (int) $settings->get( 'stale_minutes' ) ) as $run ) {
			if ( $run->attempts < $max && Run::STATUS_CANCELLING !== $run->status ) {
				$runs->update(
					$run->id,
					array(
						'attempts'     => $run->attempts + 1,
						'heartbeat_at' => current_time( 'mysql', true ),
					)
				);
				$logger->warning( 'Stalled run detected; resuming from the last committed batch.', array( 'attempt' => $run->attempts + 1, 'cursor' => $run->cursor ), $run->id );
				$this->enqueue( $run->id );
				continue;
			}
			if ( Run::STATUS_CANCELLING === $run->status ) {
				$this->plugin->engine()->finish( $run, Run::STATUS_CANCELLED, __( 'Cancelled by user.', 'micro-sync-pro' ) );
				continue;
			}
			// Keep the staged data so the run can be resumed manually.
			$runs->recount( $run->id );
			$runs->update(
				$run->id,
				array(
					'status'      => Run::STATUS_INTERRUPTED,
					'message'     => __( 'The run stopped responding and was marked as interrupted. It can be resumed from the History page.', 'micro-sync-pro' ),
					'finished_at' => current_time( 'mysql', true ),
				)
			);
			$logger->error( 'Run marked as interrupted after repeated stalls.', array( 'attempts' => $run->attempts ), $run->id );
			$final = $runs->find( $run->id );
			if ( $final ) {
				/** This action is documented in src/Sync/SyncEngine.php */
				do_action( 'micro_sync_pro_run_finished', $final );
			}
		}
	}
}
