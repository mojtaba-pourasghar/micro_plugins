<?php
/**
 * Synchronization engine (phased, batched, resumable).
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Sync;

use MicroStudio\MicroSyncPro\Logging\Logger;
use MicroStudio\MicroSyncPro\Matching\SkuMatcher;
use MicroStudio\MicroSyncPro\Repository\ItemRepository;
use MicroStudio\MicroSyncPro\Repository\RunRepository;
use MicroStudio\MicroSyncPro\Repository\SourceRepository;
use MicroStudio\MicroSyncPro\Settings\Settings;
use MicroStudio\MicroSyncPro\Sources\SourceStager;
use MicroStudio\MicroSyncPro\Sources\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Drives a run through prepare → process (batches) → finalize. Each batch
 * commits its per-record results and the new cursor before the next one
 * starts, so an interrupted run can resume from the last committed cursor and
 * already-processed rows are never applied twice.
 */
class SyncEngine {

	/** @var RunRepository */
	private $runs;
	/** @var ItemRepository */
	private $items;
	/** @var SourceRepository */
	private $sources;
	/** @var SourceStager */
	private $stager;
	/** @var SkuMatcher */
	private $matcher;
	/** @var ProductUpdater */
	private $updater;
	/** @var RollbackService */
	private $rollback;
	/** @var Storage */
	private $storage;
	/** @var Settings */
	private $settings;
	/** @var Logger */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param RunRepository    $runs     Runs.
	 * @param ItemRepository   $items    Items.
	 * @param SourceRepository $sources  Sources.
	 * @param SourceStager     $stager   Stager.
	 * @param SkuMatcher       $matcher  Matcher.
	 * @param ProductUpdater   $updater  Updater.
	 * @param RollbackService  $rollback Rollback.
	 * @param Storage          $storage  Storage.
	 * @param Settings         $settings Settings.
	 * @param Logger           $logger   Logger.
	 */
	public function __construct( RunRepository $runs, ItemRepository $items, SourceRepository $sources, SourceStager $stager, SkuMatcher $matcher, ProductUpdater $updater, RollbackService $rollback, Storage $storage, Settings $settings, Logger $logger ) {
		$this->runs     = $runs;
		$this->items    = $items;
		$this->sources  = $sources;
		$this->stager   = $stager;
		$this->matcher  = $matcher;
		$this->updater  = $updater;
		$this->rollback = $rollback;
		$this->storage  = $storage;
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Work on a run until it finishes or the deadline passes.
	 *
	 * @param Run           $run      Run.
	 * @param float         $deadline microtime(true) deadline.
	 * @param callable|null $on_batch Called after each committed batch (lock renewal).
	 */
	public function step( Run $run, float $deadline, ?callable $on_batch = null ): Run {
		$snapshot = isset( $run->snapshot['settings'] ) && is_array( $run->snapshot['settings'] ) ? $run->snapshot['settings'] : null;
		$this->settings->set_override( $snapshot );
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		try {
			if ( Run::STATUS_QUEUED === $run->status ) {
				if ( $this->runs->transition( $run->id, array( Run::STATUS_QUEUED ), Run::STATUS_RUNNING ) ) {
					$this->runs->update( $run->id, array( 'started_at' => current_time( 'mysql', true ) ) );
					$this->logger->info( 'Run started.', array( 'mode' => $run->mode, 'trigger' => $run->trigger ), $run->id );
				}
			}
			$batches = 0;
			while ( true ) {
				$run = $this->runs->find( $run->id );
				if ( ! $run || ! $run->is_active() ) {
					break;
				}
				if ( Run::STATUS_CANCELLING === $run->status ) {
					$this->finish( $run, Run::STATUS_CANCELLED, __( 'Cancelled by user.', 'micro-sync-pro' ) );
					break;
				}
				if ( $batches > 0 && microtime( true ) >= $deadline ) {
					break;
				}
				if ( Run::PHASE_PREPARE === $run->phase ) {
					$this->prepare( $run );
				} elseif ( Run::PHASE_PROCESS === $run->phase ) {
					if ( Run::MODE_ROLLBACK === $run->mode ) {
						$this->rollback_batch( $run );
					} else {
						$this->process_batch( $run );
					}
				} elseif ( Run::PHASE_FINALIZE === $run->phase ) {
					$this->finalize( $run );
				} else {
					break;
				}
				++$batches;
				if ( $on_batch ) {
					call_user_func( $on_batch, $run );
				}
			}
		} catch ( \Throwable $e ) {
			$this->logger->error( 'Run failed with an unexpected error.', array( 'error' => $e->getMessage(), 'file' => basename( $e->getFile() ) . ':' . $e->getLine() ), $run->id );
			$fresh = $this->runs->find( $run->id );
			if ( $fresh && $fresh->is_active() ) {
				/* translators: %s: error message. */
				$this->finish( $fresh, Run::STATUS_FAILED, sprintf( __( 'Unexpected error: %s', 'micro-sync-pro' ), $e->getMessage() ) );
			}
		} finally {
			$this->settings->set_override( null );
		}
		$fresh = $this->runs->find( $run->id );
		return $fresh ? $fresh : $run;
	}

	/**
	 * Prepare phase.
	 *
	 * @param Run $run Run.
	 */
	private function prepare( Run $run ): void {
		if ( Run::MODE_ROLLBACK === $run->mode ) {
			$total = $this->items->count_updated( $run->parent_run_id );
			$this->runs->update(
				$run->id,
				array(
					'total'  => $total,
					'phase'  => Run::PHASE_PROCESS,
					'cursor' => 0,
				)
			);
			return;
		}
		if ( '' !== $run->staged_file ) {
			// Apply-from-preview: data was staged by the preview run.
			$this->runs->update( $run->id, array( 'phase' => Run::PHASE_PROCESS ) );
			return;
		}
		$source = $this->sources->find( $run->source_id );
		if ( ! $source ) {
			$this->finish( $run, Run::STATUS_FAILED, __( 'The data source no longer exists.', 'micro-sync-pro' ) );
			return;
		}
		// Use the mapping captured when the run was created.
		if ( ! empty( $run->snapshot['mapping'] ) ) {
			$source->mapping = $run->snapshot['mapping'];
		}
		$staged = $this->stager->stage( $source );
		if ( is_wp_error( $staged ) ) {
			$this->logger->error( 'Staging failed.', array( 'error' => $staged->get_error_message() ), $run->id );
			$this->finish( $run, Run::STATUS_FAILED, $staged->get_error_message() );
			return;
		}
		$snapshot             = $run->snapshot;
		$snapshot['dup_file'] = $staged['dup_file'];
		$this->runs->update(
			$run->id,
			array(
				'staged_file' => $staged['file'],
				'total'       => $staged['total'],
				'cursor'      => 0,
				'phase'       => Run::PHASE_PROCESS,
				'snapshot'    => $snapshot,
			)
		);
		$this->logger->info(
			'Source staged.',
			array(
				'records'        => $staged['total'],
				'duplicate_skus' => $staged['duplicates'],
				'empty_skus'     => $staged['empty_sku'],
			),
			$run->id
		);
	}

	/**
	 * Process one batch of staged records.
	 *
	 * @param Run $run Run.
	 */
	private function process_batch( Run $run ): void {
		$batch = $this->stager->read_batch( $run->staged_file, $run->cursor, (int) $this->settings->get( 'batch_size' ) );
		if ( is_wp_error( $batch ) ) {
			$this->finish( $run, Run::STATUS_FAILED, $batch->get_error_message() );
			return;
		}
		$records = $batch['records'];
		$apply   = Run::MODE_APPLY === $run->mode;
		$opts    = isset( $run->snapshot['options'] ) && is_array( $run->snapshot['options'] ) ? $run->snapshot['options'] : array();
		$dups    = $this->stager->duplicates( (string) ( $run->snapshot['dup_file'] ?? '' ) );

		$existing = $this->items->existing_rows(
			$run->id,
			array_map(
				static function ( $r ) {
					return (int) $r['r'];
				},
				$records
			)
		);
		$skus     = array();
		foreach ( $records as $rec ) {
			$sku = trim( (string) ( $rec['d']['sku'] ?? '' ) );
			if ( '' !== $sku && ! isset( $existing[ (int) $rec['r'] ] ) && ! isset( $dups[ $sku ] ) ) {
				$skus[] = $sku;
			}
		}
		$matches = empty( $skus ) ? array() : $this->matcher->match( $skus );

		wp_defer_term_counting( true );
		foreach ( $records as $rec ) {
			$row = (int) $rec['r'];
			if ( isset( $existing[ $row ] ) ) {
				continue;
			}
			$result = $this->process_record( $rec['d'], $dups, $matches, $opts, $apply );
			$this->items->insert( $run->id, $row, $result );
		}
		wp_defer_term_counting( false );

		$counts = $this->runs->recount( $run->id );
		$fields = array(
			'cursor'       => $batch['offset'],
			'heartbeat_at' => current_time( 'mysql', true ),
		);
		if ( $batch['eof'] || empty( $records ) ) {
			$fields['phase'] = Run::PHASE_FINALIZE;
		}
		$this->runs->update( $run->id, $fields );
		unset( $counts );
	}

	/**
	 * Process a single record.
	 *
	 * @param array<string,string> $data    Record.
	 * @param array<string,int>    $dups    Duplicate SKUs.
	 * @param array<string,int[]>  $matches Matches.
	 * @param array<string,mixed>  $opts    Options.
	 * @param bool                 $apply   Apply.
	 * @return array<string,mixed>
	 */
	public function process_record( array $data, array $dups, array $matches, array $opts, bool $apply ): array {
		$sku  = trim( (string) ( $data['sku'] ?? '' ) );
		$base = array(
			'sku'        => $sku,
			'product_id' => 0,
		);
		if ( '' === $sku ) {
			return $base + array(
				'status'  => ProductUpdater::STATUS_SKIPPED,
				'code'    => 'empty_sku',
				'message' => __( 'The record has no SKU.', 'micro-sync-pro' ),
			);
		}
		if ( isset( $dups[ $sku ] ) ) {
			return $base + array(
				'status'  => ProductUpdater::STATUS_SKIPPED,
				'code'    => 'duplicate_sku',
				/* translators: %d: occurrences. */
				'message' => sprintf( __( 'This SKU appears %d times in the source; all occurrences were skipped to avoid an ambiguous update.', 'micro-sync-pro' ), (int) $dups[ $sku ] ),
			);
		}
		$ids = $matches[ $sku ] ?? array();
		if ( empty( $ids ) ) {
			return $base + array(
				'status'  => ProductUpdater::STATUS_SKIPPED,
				'code'    => 'unmatched',
				'message' => __( 'No product or variation with this SKU exists. New products are never created.', 'micro-sync-pro' ),
			);
		}
		if ( count( $ids ) > 1 ) {
			return $base + array(
				'status'  => ProductUpdater::STATUS_SKIPPED,
				'code'    => 'ambiguous_match',
				/* translators: %s: product IDs. */
				'message' => sprintf( __( 'Several products share this SKU (IDs: %s); none was updated.', 'micro-sync-pro' ), implode( ', ', array_map( 'intval', $ids ) ) ),
			);
		}
		$base['product_id'] = (int) $ids[0];
		$product            = wc_get_product( $base['product_id'] );
		if ( ! $product instanceof \WC_Product ) {
			return $base + array(
				'status'  => ProductUpdater::STATUS_FAILED,
				'code'    => 'product_load',
				'message' => __( 'The matched product could not be loaded.', 'micro-sync-pro' ),
			);
		}
		try {
			$result = $this->updater->process( $product, $data, $opts, $apply );
		} catch ( \Throwable $e ) {
			$result = array(
				'status'  => ProductUpdater::STATUS_FAILED,
				'code'    => 'exception',
				'changes' => array(),
				'message' => wp_strip_all_tags( $e->getMessage() ),
			);
		}
		return $base + $result;
	}

	/**
	 * Rollback batch.
	 *
	 * @param Run $run Run.
	 */
	private function rollback_batch( Run $run ): void {
		$rows = $this->items->updated_after( $run->parent_run_id, $run->cursor, (int) $this->settings->get( 'batch_size' ) );
		if ( empty( $rows ) ) {
			$this->runs->update( $run->id, array( 'phase' => Run::PHASE_FINALIZE ) );
			return;
		}
		$last = $run->cursor;
		foreach ( $rows as $item ) {
			$result = $this->rollback->revert( $item );
			$this->items->insert( $run->id, (int) $item->id, $result );
			$last = (int) $item->id;
		}
		$this->runs->recount( $run->id );
		$this->runs->update(
			$run->id,
			array(
				'cursor'       => $last,
				'heartbeat_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Finalize a run that processed all records.
	 *
	 * @param Run $run Run.
	 */
	private function finalize( Run $run ): void {
		$c = $this->runs->recount( $run->id );
		if ( Run::MODE_ROLLBACK === $run->mode && $run->parent_run_id ) {
			$parent = $this->runs->find( $run->parent_run_id );
			if ( $parent ) {
				$snap                   = $parent->snapshot;
				$snap['rolled_back_by'] = $run->id;
				$this->runs->update( $parent->id, array( 'snapshot' => $snap ) );
			}
		}
		$message = sprintf(
			/* translators: 1: processed, 2: updated, 3: unchanged, 4: skipped, 5: failed. */
			__( 'Processed %1$d records: %2$d updated, %3$d unchanged, %4$d skipped, %5$d failed.', 'micro-sync-pro' ),
			$c['processed'],
			$c['updated'],
			$c['unchanged'],
			$c['skipped'],
			$c['failed']
		);
		if ( Run::MODE_DRY_RUN === $run->mode ) {
			$message = sprintf(
				/* translators: 1: processed, 2: would update, 3: unchanged, 4: skipped, 5: failed. */
				__( 'Preview of %1$d records: %2$d would be updated, %3$d unchanged, %4$d skipped, %5$d failed.', 'micro-sync-pro' ),
				$c['processed'],
				$c['updated'],
				$c['unchanged'],
				$c['skipped'],
				$c['failed']
			);
		}
		if ( Run::MODE_ROLLBACK === $run->mode ) {
			$message = sprintf(
				/* translators: 1: processed, 2: reverted, 3: skipped, 4: failed. */
				__( 'Rollback of %1$d products: %2$d reverted, %3$d skipped, %4$d failed.', 'micro-sync-pro' ),
				$c['processed'],
				$c['updated'],
				$c['skipped'],
				$c['failed']
			);
		}
		$this->finish( $run, Run::STATUS_COMPLETED, $message );
	}

	/**
	 * Move a run to a terminal status and clean up its files.
	 *
	 * @param Run    $run     Run.
	 * @param string $status  Status.
	 * @param string $message Message.
	 */
	public function finish( Run $run, string $status, string $message ): void {
		$this->runs->recount( $run->id );
		$this->runs->update(
			$run->id,
			array(
				'status'      => $status,
				'phase'       => Run::PHASE_DONE,
				'message'     => $message,
				'finished_at' => current_time( 'mysql', true ),
			)
		);
		// Keep a completed preview's staged data so it can be applied; remove otherwise.
		$keep = Run::MODE_DRY_RUN === $run->mode && Run::STATUS_COMPLETED === $status;
		if ( ! $keep ) {
			if ( '' !== $run->staged_file ) {
				$this->storage->delete( $run->staged_file );
				$this->runs->update( $run->id, array( 'staged_file' => '' ) );
			}
			if ( ! empty( $run->snapshot['dup_file'] ) ) {
				$this->storage->delete( (string) $run->snapshot['dup_file'] );
			}
		}
		if ( $run->source_id ) {
			$this->sources->set_last_run( $run->source_id, $run->id );
		}
		$level = Run::STATUS_COMPLETED === $status ? 'info' : ( Run::STATUS_CANCELLED === $status ? 'warning' : 'error' );
		$this->logger->log( $level, 'Run finished: ' . $status . '. ' . $message, array(), $run->id );

		$final = $this->runs->find( $run->id );
		if ( $final ) {
			/**
			 * Fires when a run reaches a terminal status (e.g. for notifications).
			 *
			 * @param Run $final Run.
			 */
			do_action( 'micro_sync_pro_run_finished', $final );
		}
	}
}
