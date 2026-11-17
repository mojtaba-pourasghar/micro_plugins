<?php
/**
 * Run lifecycle operations.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Sync;

use MicroStudio\MicroSyncPro\Logging\Logger;
use MicroStudio\MicroSyncPro\Mapping\FieldMapper;
use MicroStudio\MicroSyncPro\Plugin;
use MicroStudio\MicroSyncPro\Repository\RunRepository;
use MicroStudio\MicroSyncPro\Repository\SourceRepository;
use MicroStudio\MicroSyncPro\Scheduling\JobQueue;
use MicroStudio\MicroSyncPro\Sources\Storage;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Creates, applies, cancels, resumes and rolls back runs, enforcing that only
 * one run per source is active at a time.
 */
class RunManager {

	/** @var RunRepository */
	private $runs;
	/** @var SourceRepository */
	private $sources;
	/** @var Storage */
	private $storage;
	/** @var Logger */
	private $logger;
	/** @var JobQueue */
	private $queue;

	/**
	 * Constructor.
	 *
	 * @param RunRepository    $runs    Runs.
	 * @param SourceRepository $sources Sources.
	 * @param Storage          $storage Storage.
	 * @param Logger           $logger  Logger.
	 * @param JobQueue         $queue   Queue.
	 */
	public function __construct( RunRepository $runs, SourceRepository $sources, Storage $storage, Logger $logger, JobQueue $queue ) {
		$this->runs    = $runs;
		$this->sources = $sources;
		$this->storage = $storage;
		$this->logger  = $logger;
		$this->queue   = $queue;
	}

	/**
	 * Start a dry run or a direct apply for a source.
	 *
	 * @param int    $source_id Source.
	 * @param string $mode      dry_run|apply.
	 * @param string $trigger   manual|schedule.
	 * @param int    $user_id   User.
	 * @return Run|WP_Error
	 */
	public function start( int $source_id, string $mode, string $trigger, int $user_id ) {
		if ( ! in_array( $mode, array( Run::MODE_DRY_RUN, Run::MODE_APPLY ), true ) ) {
			return new WP_Error( 'msp_mode', __( 'Invalid run mode.', 'micro-sync-pro' ) );
		}
		$source = $this->sources->find( $source_id );
		if ( ! $source ) {
			return new WP_Error( 'msp_source_missing', __( 'The data source does not exist.', 'micro-sync-pro' ) );
		}
		if ( 'active' !== $source->status ) {
			return new WP_Error( 'msp_source_inactive', __( 'The data source is disabled.', 'micro-sync-pro' ) );
		}
		if ( ! ( new FieldMapper() )->is_complete( $source->mapping ) ) {
			return new WP_Error( 'msp_mapping_incomplete', __( 'Configure the field mapping for this source first.', 'micro-sync-pro' ) );
		}
		$settings = Plugin::instance()->settings()->all();
		return $this->create_exclusive(
			array(
				'source_id' => $source->id,
				'mode'      => $mode,
				'trigger'   => $trigger,
				'user_id'   => $user_id,
				'snapshot'  => array(
					'settings'    => $settings,
					'options'     => $source->processing_options(),
					'mapping'     => $source->mapping,
					'source_name' => $source->name,
				),
			)
		);
	}

	/**
	 * Apply a completed preview using exactly the data and settings it previewed.
	 *
	 * @param int $preview_id Preview run.
	 * @param int $user_id    User.
	 * @return Run|WP_Error
	 */
	public function apply_preview( int $preview_id, int $user_id ) {
		$preview = $this->runs->find( $preview_id );
		if ( ! $preview || Run::MODE_DRY_RUN !== $preview->mode || Run::STATUS_COMPLETED !== $preview->status ) {
			return new WP_Error( 'msp_preview_invalid', __( 'Only a completed preview can be applied.', 'micro-sync-pro' ) );
		}
		if ( ! empty( $preview->snapshot['applied_by'] ) ) {
			return new WP_Error( 'msp_preview_used', __( 'This preview has already been applied. Run a new preview.', 'micro-sync-pro' ) );
		}
		if ( '' === $preview->staged_file || ! $this->storage->exists( $preview->staged_file ) ) {
			return new WP_Error( 'msp_preview_expired', __( 'The preview data has expired. Run a new preview.', 'micro-sync-pro' ) );
		}
		$source = $this->sources->find( $preview->source_id );
		if ( ! $source || 'active' !== $source->status ) {
			return new WP_Error( 'msp_source_missing', __( 'The data source does not exist or is disabled.', 'micro-sync-pro' ) );
		}
		$staged = $this->storage->duplicate( $preview->staged_file, 'jsonl' );
		if ( '' === $staged ) {
			return new WP_Error( 'msp_storage', __( 'The preview data could not be copied.', 'micro-sync-pro' ) );
		}
		$snapshot = $preview->snapshot;
		unset( $snapshot['applied_by'], $snapshot['dup_file'] );
		if ( ! empty( $preview->snapshot['dup_file'] ) && $this->storage->exists( (string) $preview->snapshot['dup_file'] ) ) {
			$snapshot['dup_file'] = $this->storage->duplicate( (string) $preview->snapshot['dup_file'], 'json' );
		}
		$run = $this->create_exclusive(
			array(
				'source_id'     => $preview->source_id,
				'parent_run_id' => $preview->id,
				'mode'          => Run::MODE_APPLY,
				'trigger'       => 'manual',
				'user_id'       => $user_id,
				'staged_file'   => $staged,
				'total'         => $preview->total,
				'snapshot'      => $snapshot,
			)
		);
		if ( is_wp_error( $run ) ) {
			$this->storage->delete( $staged );
			if ( ! empty( $snapshot['dup_file'] ) ) {
				$this->storage->delete( (string) $snapshot['dup_file'] );
			}
			return $run;
		}
		$psnap               = $preview->snapshot;
		$psnap['applied_by'] = $run->id;
		$this->runs->update( $preview->id, array( 'snapshot' => $psnap ) );
		return $run;
	}

	/**
	 * Start a rollback of an applied run.
	 *
	 * @param int $run_id  Applied run.
	 * @param int $user_id User.
	 * @return Run|WP_Error
	 */
	public function rollback( int $run_id, int $user_id ) {
		$target = $this->runs->find( $run_id );
		if ( ! $target || ! $target->can_rollback() ) {
			return new WP_Error( 'msp_rollback_invalid', __( 'This run cannot be rolled back.', 'micro-sync-pro' ) );
		}
		return $this->create_exclusive(
			array(
				'source_id'     => $target->source_id,
				'parent_run_id' => $target->id,
				'mode'          => Run::MODE_ROLLBACK,
				'trigger'       => 'manual',
				'user_id'       => $user_id,
				'snapshot'      => array(
					'settings'    => $target->snapshot['settings'] ?? array(),
					'source_name' => $target->snapshot['source_name'] ?? '',
				),
			)
		);
	}

	/**
	 * Create a run while guaranteeing a single active run per source.
	 *
	 * @param array<string,mixed> $data Data.
	 * @return Run|WP_Error
	 */
	private function create_exclusive( array $data ) {
		$source_id = (int) $data['source_id'];
		$active    = $this->runs->active_for_source( $source_id );
		if ( $active ) {
			return new WP_Error(
				'msp_run_active',
				sprintf(
					/* translators: %d: run id. */
					__( 'Another synchronization (#%d) is already running for this source.', 'micro-sync-pro' ),
					$active->id
				)
			);
		}
		$run = $this->runs->create( $data );
		if ( ! $run ) {
			return new WP_Error( 'msp_db', __( 'The run could not be created.', 'micro-sync-pro' ) );
		}
		// Race check: if two requests inserted concurrently the oldest wins.
		$winner = $this->runs->active_for_source( $source_id );
		if ( $winner && $winner->id !== $run->id ) {
			$this->runs->update(
				$run->id,
				array(
					'status'      => Run::STATUS_CANCELLED,
					'phase'       => Run::PHASE_DONE,
					'message'     => __( 'Cancelled: another run for this source started at the same time.', 'micro-sync-pro' ),
					'finished_at' => current_time( 'mysql', true ),
				)
			);
			if ( ! empty( $data['staged_file'] ) ) {
				$this->storage->delete( (string) $data['staged_file'] );
			}
			return new WP_Error( 'msp_run_active', __( 'Another synchronization is already running for this source.', 'micro-sync-pro' ) );
		}
		$this->logger->info( 'Run queued.', array( 'mode' => $run->mode, 'trigger' => $run->trigger, 'source_id' => $source_id ), $run->id );
		$this->queue->enqueue( $run->id );
		return $run;
	}

	/**
	 * Request cancellation (applied at the next batch boundary).
	 *
	 * @param int $run_id Run.
	 * @return true|WP_Error
	 */
	public function cancel( int $run_id ) {
		$run = $this->runs->find( $run_id );
		if ( ! $run || ! $run->is_active() ) {
			return new WP_Error( 'msp_cancel_invalid', __( 'This run is not active.', 'micro-sync-pro' ) );
		}
		if ( Run::STATUS_QUEUED === $run->status && ! $this->runs->is_locked( $run_id ) ) {
			if ( $this->runs->transition( $run_id, array( Run::STATUS_QUEUED ), Run::STATUS_CANCELLED ) ) {
				Plugin::instance()->engine()->finish( $this->runs->find( $run_id ), Run::STATUS_CANCELLED, __( 'Cancelled by user before it started.', 'micro-sync-pro' ) );
				return true;
			}
		}
		$this->runs->transition( $run_id, array( Run::STATUS_QUEUED, Run::STATUS_RUNNING ), Run::STATUS_CANCELLING );
		$this->queue->enqueue( $run_id );
		$this->logger->warning( 'Cancellation requested.', array(), $run_id );
		return true;
	}

	/**
	 * Resume an interrupted run from its last committed cursor.
	 *
	 * @param int $run_id Run.
	 * @return true|WP_Error
	 */
	public function resume( int $run_id ) {
		$run = $this->runs->find( $run_id );
		if ( ! $run || Run::STATUS_INTERRUPTED !== $run->status ) {
			return new WP_Error( 'msp_resume_invalid', __( 'Only interrupted runs can be resumed.', 'micro-sync-pro' ) );
		}
		if ( Run::MODE_ROLLBACK !== $run->mode && Run::PHASE_PREPARE !== $run->phase && ! $this->storage->exists( $run->staged_file ) ) {
			return new WP_Error( 'msp_resume_data', __( 'The staged data of this run is no longer available; it cannot be resumed.', 'micro-sync-pro' ) );
		}
		$other = $this->runs->active_for_source( $run->source_id );
		if ( $other ) {
			return new WP_Error( 'msp_run_active', __( 'Another synchronization is already running for this source.', 'micro-sync-pro' ) );
		}
		if ( ! $this->runs->transition( $run_id, array( Run::STATUS_INTERRUPTED ), Run::STATUS_RUNNING ) ) {
			return new WP_Error( 'msp_resume_invalid', __( 'Only interrupted runs can be resumed.', 'micro-sync-pro' ) );
		}
		$this->runs->update(
			$run_id,
			array(
				'attempts'     => 0,
				'heartbeat_at' => current_time( 'mysql', true ),
				'finished_at'  => null,
			)
		);
		$this->logger->info( 'Run resumed manually.', array( 'cursor' => $run->cursor ), $run_id );
		$this->queue->enqueue( $run_id );
		return true;
	}
}
