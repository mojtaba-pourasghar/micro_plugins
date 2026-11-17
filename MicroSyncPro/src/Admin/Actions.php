<?php
/**
 * Form handlers (admin-post.php).
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Admin;

use MicroStudio\MicroSyncPro\Plugin;
use MicroStudio\MicroSyncPro\Repository\SourceRepository;
use MicroStudio\MicroSyncPro\Security\UploadHandler;
use MicroStudio\MicroSyncPro\Sources\Source;
use MicroStudio\MicroSyncPro\Sources\SourceTypes;
use MicroStudio\MicroSyncPro\Sync\Run;

defined( 'ABSPATH' ) || exit;

/**
 * Every handler verifies the nonce and capability, validates all input on
 * the server, and redirects back (Post/Redirect/Get) with a flash notice.
 */
class Actions {

	/** @var Plugin */
	private $plugin;

	/**
	 * Action name => method.
	 *
	 * @var array<string,string>
	 */
	const ACTIONS = array(
		'msp_save_source'    => 'save_source',
		'msp_delete_source'  => 'delete_source',
		'msp_detect_columns' => 'detect_columns',
		'msp_save_mapping'   => 'save_mapping',
		'msp_start_run'      => 'start_run',
		'msp_apply_preview'  => 'apply_preview',
		'msp_cancel_run'     => 'cancel_run',
		'msp_resume_run'     => 'resume_run',
		'msp_rollback_run'   => 'rollback_run',
		'msp_save_schedule'  => 'save_schedule',
		'msp_save_settings'  => 'save_settings',
		'msp_export_run'     => 'export_run',
		'msp_cleanup_now'    => 'cleanup_now',
	);

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Register admin-post hooks.
	 */
	public function register_hooks(): void {
		foreach ( self::ACTIONS as $action => $method ) {
			add_action( 'admin_post_' . $action, array( $this, $method ) );
		}
	}

	/**
	 * Verify nonce + capability or die.
	 *
	 * @param string $action Action.
	 */
	private function guard( string $action ): void {
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'micro-sync-pro' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirect helper.
	 *
	 * @param string              $page Page slug.
	 * @param array<string,mixed> $args Args.
	 */
	private function redirect( string $page, array $args = array() ): void {
		wp_safe_redirect( View::url( $page, $args ) );
		exit;
	}

	/**
	 * Integer from POST.
	 *
	 * @param string $key Key.
	 */
	private static function post_int( string $key ): int {
		return isset( $_POST[ $key ] ) ? absint( wp_unslash( $_POST[ $key ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
	}

	/**
	 * Create or update a source.
	 */
	public function save_source(): void {
		$this->guard( 'msp_save_source' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$sources  = $this->plugin->sources();
		$id       = self::post_int( 'source_id' );
		$existing = $id ? $sources->find( $id ) : null;
		if ( $id && ! $existing ) {
			Notices::add( 'error', __( 'The data source does not exist.', 'micro-sync-pro' ) );
			$this->redirect( 'msp-sources' );
		}
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$type = $existing ? $existing->type : ( isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '' );
		$back = array( 'action' => $existing ? 'edit' : 'new' ) + ( $existing ? array( 'source' => $existing->id ) : array() );

		if ( '' === $name ) {
			Notices::add( 'error', __( 'Enter a name for the data source.', 'micro-sync-pro' ) );
			$this->redirect( 'msp-sources', $back );
		}
		if ( ! in_array( $type, SourceTypes::all(), true ) ) {
			Notices::add( 'error', __( 'Select a valid source type.', 'micro-sync-pro' ) );
			$this->redirect( 'msp-sources', $back );
		}
		$raw_config = isset( $_POST['config'] ) && is_array( $_POST['config'] ) ? wp_unslash( $_POST['config'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each key is validated in build_config().
		$config     = $sources->build_config( $type, $raw_config, $existing );
		if ( is_wp_error( $config ) ) {
			Notices::add( 'error', $config->get_error_message() );
			$this->redirect( 'msp-sources', $back );
		}

		$url_changed = false;
		if ( SourceTypes::is_remote( $type ) ) {
			$new_url = isset( $raw_config['url'] ) ? trim( (string) $raw_config['url'] ) : '';
			if ( '' !== $new_url ) {
				$valid = $this->plugin->url_validator()->validate( $new_url );
				if ( is_wp_error( $valid ) ) {
					Notices::add( 'error', $valid->get_error_message() );
					$this->redirect( 'msp-sources', $back );
				}
				$url_changed = true;
			}
		}

		$file_changed = false;
		$old_file     = $existing ? (string) $existing->opt( 'file', '' ) : '';
		if ( ! SourceTypes::is_remote( $type ) ) {
			$has_upload = isset( $_FILES['source_file'] ) && is_array( $_FILES['source_file'] ) && isset( $_FILES['source_file']['error'] ) && UPLOAD_ERR_NO_FILE !== (int) $_FILES['source_file']['error'];
			if ( $has_upload ) {
				$max     = min( (int) $this->plugin->settings()->get( 'max_upload_mb' ) * MB_IN_BYTES, (int) wp_max_upload_size() );
				$handler = new UploadHandler( $this->plugin->storage(), $max );
				$upload  = $handler->handle( $_FILES['source_file'], SourceTypes::format( $type ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by UploadHandler.
				if ( is_wp_error( $upload ) ) {
					Notices::add( 'error', $upload->get_error_message() );
					$this->redirect( 'msp-sources', $back );
				}
				$config['file']          = $upload['name'];
				$config['original_name'] = $upload['original'];
				$file_changed            = true;
			} elseif ( '' === $old_file ) {
				Notices::add( 'error', __( 'Upload a file for this data source.', 'micro-sync-pro' ) );
				$this->redirect( 'msp-sources', $back );
			}
		}

		$source           = $existing ? $existing : new Source();
		$source->name     = $name;
		$source->type     = $type;
		$source->config   = $config;
		$source->status   = empty( $_POST['enabled'] ) ? 'inactive' : 'active';
		$parser_changed   = $existing && wp_json_encode( $existing->parser_options() ) !== wp_json_encode( $source->parser_options() );
		$result           = $sources->save( $source );
		// phpcs:enable
		if ( is_wp_error( $result ) ) {
			if ( $file_changed ) {
				$this->plugin->storage()->delete( (string) $config['file'] );
			}
			Notices::add( 'error', $result->get_error_message() );
			$this->redirect( 'msp-sources', $back );
		}
		if ( $file_changed && '' !== $old_file && $old_file !== $config['file'] ) {
			$this->plugin->storage()->delete( $old_file );
		}
		$this->plugin->scheduler()->sync_source( $source );
		$this->plugin->logger()->info( $existing ? 'Data source updated.' : 'Data source created.', array( 'source_id' => $source->id, 'type' => $type ) );

		if ( ! $existing || $file_changed || $url_changed || $parser_changed || empty( $source->columns ) ) {
			$detected = $this->inspect_and_store( $source );
			if ( $detected ) {
				Notices::add( 'success', __( 'Data source saved and columns detected. Review the field mapping.', 'micro-sync-pro' ) );
				$this->redirect( 'msp-mapping', array( 'source' => $source->id ) );
			}
			Notices::add( 'warning', __( 'Data source saved, but its columns could not be read yet.', 'micro-sync-pro' ) );
			$this->redirect( 'msp-sources' );
		}
		Notices::add( 'success', __( 'Data source saved.', 'micro-sync-pro' ) );
		$this->redirect( 'msp-sources' );
	}

	/**
	 * Read columns + sample of a source and store them.
	 *
	 * @param Source $source Source.
	 */
	private function inspect_and_store( Source $source ): bool {
		$info = $this->plugin->stager()->inspect( $source );
		if ( is_wp_error( $info ) ) {
			Notices::add( 'error', $info->get_error_message() );
			return false;
		}
		if ( empty( $info['columns'] ) ) {
			Notices::add( 'error', __( 'No columns were found in the source.', 'micro-sync-pro' ) );
			return false;
		}
		$source->columns = $info['columns'];
		// Drop mapped columns that no longer exist.
		foreach ( $source->mapping as $field => $m ) {
			if ( ! empty( $m['column'] ) && ! in_array( $m['column'], $source->columns, true ) ) {
				$source->mapping[ $field ] = array(
					'column'  => '',
					'enabled' => false,
				);
			}
		}
		$this->plugin->sources()->save( $source );
		set_transient( 'msp_sample_' . $source->id, $info['sample'], HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Delete a source.
	 */
	public function delete_source(): void {
		$this->guard( 'msp_delete_source' );
		$id     = self::post_int( 'source_id' );
		$source = $this->plugin->sources()->find( $id );
		if ( ! $source ) {
			Notices::add( 'error', __( 'The data source does not exist.', 'micro-sync-pro' ) );
			$this->redirect( 'msp-sources' );
		}
		if ( $this->plugin->runs()->active_for_source( $id ) ) {
			Notices::add( 'error', __( 'This source has an active synchronization. Cancel it first.', 'micro-sync-pro' ) );
			$this->redirect( 'msp-sources' );
		}
		$this->plugin->scheduler()->unschedule_source( $id );
		$file = (string) $source->opt( 'file', '' );
		if ( '' !== $file ) {
			$this->plugin->storage()->delete( $file );
		}
		$this->plugin->sources()->delete( $id );
		delete_transient( 'msp_sample_' . $id );
		$this->plugin->logger()->info( 'Data source deleted.', array( 'source_id' => $id ) );
		Notices::add( 'success', __( 'Data source deleted. Its history is kept.', 'micro-sync-pro' ) );
		$this->redirect( 'msp-sources' );
	}

	/**
	 * Re-detect columns.
	 */
	public function detect_columns(): void {
		$this->guard( 'msp_detect_columns' );
		$source = $this->plugin->sources()->find( self::post_int( 'source_id' ) );
		if ( ! $source ) {
			Notices::add( 'error', __( 'The data source does not exist.', 'micro-sync-pro' ) );
			$this->redirect( 'msp-mapping' );
		}
		if ( $this->inspect_and_store( $source ) ) {
			/* translators: %d: number of columns. */
			Notices::add( 'success', sprintf( __( '%d columns detected.', 'micro-sync-pro' ), count( $source->columns ) ) );
		}
		$this->redirect( 'msp-mapping', array( 'source' => $source->id ) );
	}

	/**
	 * Save a field mapping.
	 */
	public function save_mapping(): void {
		$this->guard( 'msp_save_mapping' );
		$source = $this->plugin->sources()->find( self::post_int( 'source_id' ) );
		if ( ! $source ) {
			Notices::add( 'error', __( 'The data source does not exist.', 'micro-sync-pro' ) );
			$this->redirect( 'msp-mapping' );
		}
		$input   = isset( $_POST['mapping'] ) && is_array( $_POST['mapping'] ) ? wp_unslash( $_POST['mapping'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by FieldMapper::sanitize().
		$mapping = $this->plugin->mapper()->sanitize( $input, $source->columns );
		if ( is_wp_error( $mapping ) ) {
			Notices::add( 'error', $mapping->get_error_message() );
			$this->redirect( 'msp-mapping', array( 'source' => $source->id ) );
		}
		$source->mapping = $mapping;
		$this->plugin->sources()->save( $source );
		$this->plugin->logger()->info( 'Field mapping saved.', array( 'source_id' => $source->id ) );
		Notices::add( 'success', __( 'Field mapping saved. You can now preview the synchronization.', 'micro-sync-pro' ) );
		$this->redirect( 'msp-mapping', array( 'source' => $source->id ) );
	}

	/**
	 * Start a preview or a direct apply.
	 */
	public function start_run(): void {
		$this->guard( 'msp_start_run' );
		$mode = isset( $_POST['mode'] ) && Run::MODE_APPLY === $_POST['mode'] ? Run::MODE_APPLY : Run::MODE_DRY_RUN; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$run  = $this->plugin->run_manager()->start( self::post_int( 'source_id' ), $mode, 'manual', get_current_user_id() );
		if ( is_wp_error( $run ) ) {
			Notices::add( 'error', $run->get_error_message() );
			$this->redirect( 'msp-sync' );
		}
		Notices::add( 'info', Run::MODE_APPLY === $mode ? __( 'Synchronization started.', 'micro-sync-pro' ) : __( 'Preview started. No product will be changed.', 'micro-sync-pro' ) );
		$this->redirect( 'msp-sync', array( 'run' => $run->id ) );
	}

	/**
	 * Confirm and apply a preview.
	 */
	public function apply_preview(): void {
		$this->guard( 'msp_apply_preview' );
		$run = $this->plugin->run_manager()->apply_preview( self::post_int( 'run_id' ), get_current_user_id() );
		if ( is_wp_error( $run ) ) {
			Notices::add( 'error', $run->get_error_message() );
			$this->redirect( 'msp-sync', array( 'run' => self::post_int( 'run_id' ) ) );
		}
		Notices::add( 'info', __( 'Applying the previewed changes.', 'micro-sync-pro' ) );
		$this->redirect( 'msp-sync', array( 'run' => $run->id ) );
	}

	/**
	 * Cancel.
	 */
	public function cancel_run(): void {
		$this->guard( 'msp_cancel_run' );
		$id  = self::post_int( 'run_id' );
		$res = $this->plugin->run_manager()->cancel( $id );
		Notices::add( is_wp_error( $res ) ? 'error' : 'warning', is_wp_error( $res ) ? $res->get_error_message() : __( 'Cancellation requested. The run stops after the current batch.', 'micro-sync-pro' ) );
		$this->redirect( 'msp-history', array( 'run' => $id ) );
	}

	/**
	 * Resume.
	 */
	public function resume_run(): void {
		$this->guard( 'msp_resume_run' );
		$id  = self::post_int( 'run_id' );
		$res = $this->plugin->run_manager()->resume( $id );
		if ( is_wp_error( $res ) ) {
			Notices::add( 'error', $res->get_error_message() );
			$this->redirect( 'msp-history', array( 'run' => $id ) );
		}
		Notices::add( 'info', __( 'The run was resumed from its last completed batch.', 'micro-sync-pro' ) );
		$this->redirect( 'msp-sync', array( 'run' => $id ) );
	}

	/**
	 * Rollback.
	 */
	public function rollback_run(): void {
		$this->guard( 'msp_rollback_run' );
		$id  = self::post_int( 'run_id' );
		$run = $this->plugin->run_manager()->rollback( $id, get_current_user_id() );
		if ( is_wp_error( $run ) ) {
			Notices::add( 'error', $run->get_error_message() );
			$this->redirect( 'msp-history', array( 'run' => $id ) );
		}
		Notices::add( 'info', __( 'Rollback started.', 'micro-sync-pro' ) );
		$this->redirect( 'msp-sync', array( 'run' => $run->id ) );
	}

	/**
	 * Save schedules.
	 */
	public function save_schedule(): void {
		$this->guard( 'msp_save_schedule' );
		$input = isset( $_POST['schedule'] ) && is_array( $_POST['schedule'] ) ? wp_unslash( $_POST['schedule'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below.
		$count = 0;
		foreach ( $input as $source_id => $schedule ) {
			$source = $this->plugin->sources()->find( absint( $source_id ) );
			if ( ! $source ) {
				continue;
			}
			$schedule = is_string( $schedule ) && in_array( $schedule, SourceRepository::SCHEDULES, true ) ? $schedule : 'none';
			if ( 'none' !== $schedule && ! $this->plugin->mapper()->is_complete( $source->mapping ) ) {
				/* translators: %s: source name. */
				Notices::add( 'warning', sprintf( __( '"%s" needs a complete field mapping before it can be scheduled.', 'micro-sync-pro' ), $source->name ) );
				$schedule = 'none';
			}
			if ( $schedule !== $source->schedule ) {
				$source->schedule = $schedule;
				$this->plugin->sources()->save( $source );
				++$count;
			}
			$this->plugin->scheduler()->sync_source( $source );
		}
		$this->plugin->logger()->info( 'Schedules saved.', array( 'changed' => $count ) );
		Notices::add( 'success', __( 'Schedules saved.', 'micro-sync-pro' ) );
		$this->redirect( 'msp-schedule' );
	}

	/**
	 * Save settings.
	 */
	public function save_settings(): void {
		$this->guard( 'msp_save_settings' );
		$input = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by Settings::sanitize().
		foreach ( array( 'delete_data_on_uninstall', 'apply_pricing_to_sale', 'allow_zero_price', 'allow_negative_stock' ) as $bool ) {
			$input[ $bool ] = ! empty( $input[ $bool ] );
		}
		$rules = array();
		if ( isset( $input['category_rules'] ) && is_array( $input['category_rules'] ) ) {
			foreach ( $input['category_rules'] as $rule ) {
				if ( ! is_array( $rule ) || empty( $rule['term_id'] ) ) {
					continue;
				}
				$term = get_term( absint( $rule['term_id'] ), 'product_cat' );
				if ( ! $term || is_wp_error( $term ) ) {
					continue;
				}
				$rule['include_children'] = ! empty( $rule['include_children'] );
				$rules[]                  = $rule;
			}
		}
		$input['category_rules'] = $rules;
		$this->plugin->settings()->save( $input );
		$this->plugin->logger()->info( 'Settings saved.' );
		Notices::add( 'success', __( 'Settings saved.', 'micro-sync-pro' ) );
		$this->redirect( 'msp-settings' );
	}

	/**
	 * Export run results as CSV.
	 */
	public function export_run(): void {
		$this->guard( 'msp_export_run' );
		$run = $this->plugin->runs()->find( self::post_int( 'run_id' ) );
		if ( ! $run ) {
			wp_die( esc_html__( 'The run does not exist.', 'micro-sync-pro' ), 404 );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="micro-sync-run-' . (int) $run->id . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fputcsv( $out, array( 'row', 'sku', 'product_id', 'status', 'code', 'changes', 'message' ), ',', '"', '' );
		$this->plugin->items()->each(
			$run->id,
			static function ( $item ) use ( $out ) {
				$cells = array( $item->row_num, $item->sku, $item->product_id, $item->status, $item->code, $item->changes, $item->message );
				$cells = array_map( array( __CLASS__, 'csv_safe' ), $cells );
				fputcsv( $out, $cells, ',', '"', '' );
			}
		);
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Neutralize spreadsheet formula injection.
	 *
	 * @param mixed $value Value.
	 */
	public static function csv_safe( $value ): string {
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) && ! is_numeric( $value ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Manual retention cleanup.
	 */
	public function cleanup_now(): void {
		$this->guard( 'msp_cleanup_now' );
		$r = $this->plugin->retention()->run();
		/* translators: 1: logs, 2: runs, 3: files. */
		Notices::add( 'success', sprintf( __( 'Cleanup finished: %1$d log entries, %2$d runs and %3$d files removed.', 'micro-sync-pro' ), $r['logs'], $r['runs'], $r['files'] ) );
		$this->redirect( 'msp-settings' );
	}
}
