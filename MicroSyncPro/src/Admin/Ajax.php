<?php
/**
 * AJAX endpoints for live progress.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Admin;

use MicroStudio\MicroSyncPro\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The progress screen polls msp_run_tick. Each tick also processes a short
 * slice of the run (when no background worker holds the lock), so runs make
 * progress even on sites where WP-Cron is unreliable.
 */
class Ajax {

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
		add_action( 'wp_ajax_msp_run_tick', array( $this, 'tick' ) );
		add_action( 'wp_ajax_msp_run_cancel', array( $this, 'cancel' ) );
	}

	/**
	 * Verify nonce + capability.
	 */
	private function guard(): void {
		if ( ! check_ajax_referer( 'msp_ajax', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session has expired. Reload the page.', 'micro-sync-pro' ) ), 403 );
		}
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'micro-sync-pro' ) ), 403 );
		}
	}

	/**
	 * Progress tick.
	 */
	public function tick(): void {
		$this->guard();
		$run_id = isset( $_POST['run_id'] ) ? absint( wp_unslash( $_POST['run_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$run    = $this->plugin->runs()->find( $run_id );
		if ( ! $run ) {
			wp_send_json_error( array( 'message' => __( 'The run does not exist.', 'micro-sync-pro' ) ), 404 );
		}
		if ( $run->is_active() ) {
			$seconds = min( 10, (int) $this->plugin->settings()->get( 'time_limit' ) );
			$this->plugin->queue()->work( $run_id, $seconds );
			$run = $this->plugin->runs()->find( $run_id );
		}
		wp_send_json_success( $run ? $run->to_public() : array() );
	}

	/**
	 * Cancel.
	 */
	public function cancel(): void {
		$this->guard();
		$run_id = isset( $_POST['run_id'] ) ? absint( wp_unslash( $_POST['run_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$res    = $this->plugin->run_manager()->cancel( $run_id );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
		}
		$run = $this->plugin->runs()->find( $run_id );
		wp_send_json_success( $run ? $run->to_public() : array() );
	}
}
