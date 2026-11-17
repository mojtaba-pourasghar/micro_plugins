<?php
/**
 * Admin page controllers.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Admin;

use MicroStudio\MicroSyncPro\Plugin;
use MicroStudio\MicroSyncPro\Scheduling\JobQueue;
use MicroStudio\MicroSyncPro\Sources\SourceTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Collects data for each screen and renders its view.
 */
class Pages {

	const PER_PAGE = 25;

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
	 * Deny access without capability.
	 */
	private function check(): void {
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'micro-sync-pro' ), 403 );
		}
	}

	/**
	 * GET helper (sanitized key).
	 *
	 * @param string $key Key.
	 */
	private static function get_key( string $key ): string {
		return isset( $_GET[ $key ] ) ? sanitize_key( wp_unslash( $_GET[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * GET int helper.
	 *
	 * @param string $key Key.
	 */
	private static function get_int( string $key ): int {
		return isset( $_GET[ $key ] ) ? absint( wp_unslash( $_GET[ $key ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Source names by id.
	 *
	 * @return array<int,string>
	 */
	private function source_names(): array {
		$names = array();
		foreach ( $this->plugin->sources()->all() as $s ) {
			$names[ $s->id ] = $s->name;
		}
		return $names;
	}

	/**
	 * Dashboard.
	 */
	public function dashboard(): void {
		$this->check();
		$sources = $this->plugin->sources()->all();
		$recent  = $this->plugin->runs()->paginate( array(), 1, 8 );
		View::render(
			'dashboard',
			array(
				'sources'      => $sources,
				'names'        => $this->source_names(),
				'stats'        => $this->plugin->runs()->stats( 30 ),
				'active'       => $this->plugin->runs()->active(),
				'recent'       => $recent['items'],
				'changes'      => $this->plugin->items()->recent_updates( 10 ),
				'system'       => $this->system_status(),
				'scheduled'    => count( $this->plugin->sources()->scheduled() ),
			)
		);
	}

	/**
	 * System status rows.
	 *
	 * @return array<int,array{label:string,value:string,ok:bool}>
	 */
	private function system_status(): array {
		$hpos = false;
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}
		$storage = $this->plugin->storage();
		$storage->ensure_directory();
		return array(
			array(
				'label' => __( 'WooCommerce', 'micro-sync-pro' ),
				'value' => defined( 'WC_VERSION' ) ? WC_VERSION : '—',
				'ok'    => true,
			),
			array(
				'label' => __( 'Background processing', 'micro-sync-pro' ),
				'value' => JobQueue::use_action_scheduler() ? __( 'Action Scheduler', 'micro-sync-pro' ) : __( 'WP-Cron (fallback)', 'micro-sync-pro' ),
				'ok'    => true,
			),
			array(
				'label' => __( 'Order storage (HPOS)', 'micro-sync-pro' ),
				'value' => $hpos ? __( 'High-performance order storage — compatible', 'micro-sync-pro' ) : __( 'WordPress posts storage — compatible', 'micro-sync-pro' ),
				'ok'    => true,
			),
			array(
				'label' => __( 'Excel (XLSX) support', 'micro-sync-pro' ),
				'value' => SourceTypes::xlsx_supported() ? __( 'Available', 'micro-sync-pro' ) : __( 'Unavailable (PHP zip/xmlreader missing)', 'micro-sync-pro' ),
				'ok'    => SourceTypes::xlsx_supported(),
			),
			array(
				'label' => __( 'Protected storage', 'micro-sync-pro' ),
				'value' => is_writable( $storage->base_dir() ) ? __( 'Writable', 'micro-sync-pro' ) : __( 'Not writable', 'micro-sync-pro' ),
				'ok'    => is_writable( $storage->base_dir() ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			),
			array(
				'label' => __( 'WP-Cron', 'micro-sync-pro' ),
				'value' => ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ? __( 'Disabled (use a server cron job)', 'micro-sync-pro' ) : __( 'Enabled', 'micro-sync-pro' ),
				'ok'    => true,
			),
		);
	}

	/**
	 * Data sources (list / form).
	 */
	public function sources(): void {
		$this->check();
		$action = self::get_key( 'action' );
		if ( 'new' === $action || 'edit' === $action ) {
			$source = 'edit' === $action ? $this->plugin->sources()->find( self::get_int( 'source' ) ) : null;
			if ( 'edit' === $action && ! $source ) {
				Notices::add( 'error', __( 'The data source does not exist.', 'micro-sync-pro' ) );
			}
			View::render(
				'source-form',
				array(
					'source'     => $source,
					'has_secret' => $source ? $this->plugin->sources()->has_secret( $source ) : false,
					'max_upload' => min( (int) $this->plugin->settings()->get( 'max_upload_mb' ) * MB_IN_BYTES, (int) wp_max_upload_size() ),
				)
			);
			return;
		}
		$sources = $this->plugin->sources()->all();
		$last    = array();
		foreach ( $sources as $s ) {
			if ( $s->last_run_id ) {
				$last[ $s->id ] = $this->plugin->runs()->find( $s->last_run_id );
			}
		}
		View::render(
			'sources',
			array(
				'sources' => $sources,
				'last'    => $last,
				'mapper'  => $this->plugin->mapper(),
			)
		);
	}

	/**
	 * Synchronization.
	 */
	public function sync(): void {
		$this->check();
		$run_id = self::get_int( 'run' );
		$run    = $run_id ? $this->plugin->runs()->find( $run_id ) : null;
		$data   = array(
			'sources' => $this->plugin->sources()->all(),
			'mapper'  => $this->plugin->mapper(),
			'run'     => $run,
			'names'   => $this->source_names(),
			'active'  => $this->plugin->runs()->active(),
			'preselect' => self::get_int( 'source' ),
		);
		if ( $run ) {
			$data += $this->items_data( $run->id );
		}
		View::render( 'sync', $data );
	}

	/**
	 * Items list data for a run.
	 *
	 * @param int $run_id Run.
	 * @return array<string,mixed>
	 */
	private function items_data( int $run_id ): array {
		$filters = array(
			'status' => self::get_key( 'item_status' ),
			'code'   => self::get_key( 'code' ),
			'search' => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
		$page    = View::current_page();
		$items   = $this->plugin->items()->paginate( $run_id, $filters, $page, self::PER_PAGE );
		return array(
			'items'       => $items['items'],
			'items_total' => $items['total'],
			'items_page'  => $page,
			'filters'     => $filters,
			'breakdown'   => $this->plugin->items()->breakdown( $run_id ),
		);
	}

	/**
	 * Field mapping.
	 */
	public function mapping(): void {
		$this->check();
		$sources = $this->plugin->sources()->all();
		$id      = self::get_int( 'source' );
		if ( ! $id && ! empty( $sources ) ) {
			$id = $sources[0]->id;
		}
		$source  = $id ? $this->plugin->sources()->find( $id ) : null;
		$mapping = array();
		if ( $source ) {
			$mapping = $this->plugin->mapper()->is_complete( $source->mapping ) || ! empty( $source->mapping ) ? $source->mapping : $this->plugin->mapper()->suggest( $source->columns );
		}
		$sample = $source ? get_transient( 'msp_sample_' . $source->id ) : false;
		View::render(
			'mapping',
			array(
				'sources'   => $sources,
				'source'    => $source,
				'mapping'   => $mapping,
				'suggested' => $source && empty( $source->mapping ),
				'sample'    => is_array( $sample ) ? $sample : array(),
				'complete'  => $source ? $this->plugin->mapper()->is_complete( $source->mapping ) : false,
			)
		);
	}

	/**
	 * Schedule.
	 */
	public function schedule(): void {
		$this->check();
		$sources = $this->plugin->sources()->all();
		$next    = array();
		foreach ( $sources as $s ) {
			$next[ $s->id ] = $this->plugin->scheduler()->next_for_source( $s->id );
		}
		View::render(
			'schedule',
			array(
				'sources' => $sources,
				'next'    => $next,
				'mapper'  => $this->plugin->mapper(),
				'as'      => JobQueue::use_action_scheduler(),
			)
		);
	}

	/**
	 * History.
	 */
	public function history(): void {
		$this->check();
		$run_id = self::get_int( 'run' );
		if ( $run_id ) {
			$run = $this->plugin->runs()->find( $run_id );
			if ( $run ) {
				$parent = $run->parent_run_id ? $this->plugin->runs()->find( $run->parent_run_id ) : null;
				View::render(
					'run-detail',
					array(
						'run'    => $run,
						'parent' => $parent,
						'names'  => $this->source_names(),
					) + $this->items_data( $run->id )
				);
				return;
			}
			Notices::add( 'error', __( 'The run does not exist.', 'micro-sync-pro' ) );
		}
		$filters = array(
			'source_id' => self::get_int( 'source_id' ),
			'status'    => self::get_key( 'status' ),
			'mode'      => self::get_key( 'mode' ),
		);
		$page    = View::current_page();
		$runs    = $this->plugin->runs()->paginate( $filters, $page, self::PER_PAGE );
		View::render(
			'history',
			array(
				'runs'    => $runs['items'],
				'total'   => $runs['total'],
				'page'    => $page,
				'filters' => $filters,
				'names'   => $this->source_names(),
			)
		);
	}

	/**
	 * Logs.
	 */
	public function logs(): void {
		$this->check();
		$filters = array(
			'level'  => self::get_key( 'level' ),
			'run_id' => self::get_int( 'run_id' ),
			'search' => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
		$page    = View::current_page();
		$logs    = $this->plugin->log_repository()->paginate( $filters, $page, 50 );
		View::render(
			'logs',
			array(
				'logs'      => $logs['items'],
				'total'     => $logs['total'],
				'page'      => $page,
				'filters'   => $filters,
				'retention' => (int) $this->plugin->settings()->get( 'log_retention_days' ),
			)
		);
	}

	/**
	 * Settings.
	 */
	public function settings(): void {
		$this->check();
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 1000,
			)
		);
		View::render(
			'settings',
			array(
				'settings'   => $this->plugin->settings()->all(),
				'categories' => is_array( $terms ) ? $terms : array(),
			)
		);
	}
}
