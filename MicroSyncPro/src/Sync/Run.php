<?php
/**
 * Run value object.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * One synchronization execution.
 */
class Run {

	const MODE_DRY_RUN  = 'dry_run';
	const MODE_APPLY    = 'apply';
	const MODE_ROLLBACK = 'rollback';

	const STATUS_QUEUED      = 'queued';
	const STATUS_RUNNING     = 'running';
	const STATUS_CANCELLING  = 'cancelling';
	const STATUS_COMPLETED   = 'completed';
	const STATUS_FAILED      = 'failed';
	const STATUS_CANCELLED   = 'cancelled';
	const STATUS_INTERRUPTED = 'interrupted';

	const PHASE_PREPARE  = 'prepare';
	const PHASE_PROCESS  = 'process';
	const PHASE_FINALIZE = 'finalize';
	const PHASE_DONE     = 'done';

	/** @var int */
	public $id = 0;
	/** @var string */
	public $uuid = '';
	/** @var int */
	public $source_id = 0;
	/** @var int */
	public $parent_run_id = 0;
	/** @var string */
	public $mode = self::MODE_DRY_RUN;
	/** @var string */
	public $trigger = 'manual';
	/** @var string */
	public $status = self::STATUS_QUEUED;
	/** @var string */
	public $phase = self::PHASE_PREPARE;
	/** @var int */
	public $total = 0;
	/** @var int */
	public $processed = 0;
	/** @var int */
	public $updated = 0;
	/** @var int */
	public $unchanged = 0;
	/** @var int */
	public $skipped = 0;
	/** @var int */
	public $failed = 0;
	/** @var int */
	public $cursor = 0;
	/** @var string */
	public $staged_file = '';
	/** @var array<string,mixed> */
	public $snapshot = array();
	/** @var string */
	public $message = '';
	/** @var int */
	public $user_id = 0;
	/** @var int */
	public $attempts = 0;
	/** @var string */
	public $heartbeat_at = '';
	/** @var string */
	public $created_at = '';
	/** @var string */
	public $started_at = '';
	/** @var string */
	public $finished_at = '';

	/**
	 * Active (non-terminal) statuses.
	 *
	 * @return string[]
	 */
	public static function active_statuses(): array {
		return array( self::STATUS_QUEUED, self::STATUS_RUNNING, self::STATUS_CANCELLING );
	}

	/**
	 * Whether the run is still active.
	 */
	public function is_active(): bool {
		return in_array( $this->status, self::active_statuses(), true );
	}

	/**
	 * Whether this run's changes can be rolled back.
	 */
	public function can_rollback(): bool {
		return self::MODE_APPLY === $this->mode && $this->updated > 0 && ! $this->is_active() && empty( $this->snapshot['rolled_back_by'] );
	}

	/**
	 * Progress percentage.
	 */
	public function percent(): int {
		if ( in_array( $this->status, array( self::STATUS_COMPLETED ), true ) ) {
			return 100;
		}
		if ( $this->total <= 0 ) {
			return 0;
		}
		return (int) min( 100, floor( $this->processed * 100 / $this->total ) );
	}

	/**
	 * Hydrate.
	 *
	 * @param object $row Row.
	 */
	public static function from_row( $row ): Run {
		$r                = new self();
		$r->id            = (int) $row->id;
		$r->uuid          = (string) $row->uuid;
		$r->source_id     = (int) $row->source_id;
		$r->parent_run_id = (int) $row->parent_run_id;
		$r->mode          = (string) $row->mode;
		$r->trigger       = (string) $row->run_trigger;
		$r->status        = (string) $row->status;
		$r->phase         = (string) $row->phase;
		$r->total         = (int) $row->total_records;
		$r->processed     = (int) $row->processed;
		$r->updated       = (int) $row->updated_count;
		$r->unchanged     = (int) $row->unchanged_count;
		$r->skipped       = (int) $row->skipped_count;
		$r->failed        = (int) $row->failed_count;
		$r->cursor        = (int) $row->cursor_pos;
		$r->staged_file   = (string) $row->staged_file;
		$snap             = is_string( $row->settings_snapshot ) ? json_decode( $row->settings_snapshot, true ) : null;
		$r->snapshot      = is_array( $snap ) ? $snap : array();
		$r->message       = (string) $row->message;
		$r->user_id       = (int) $row->user_id;
		$r->attempts      = (int) $row->attempts;
		$r->heartbeat_at  = (string) $row->heartbeat_at;
		$r->created_at    = (string) $row->created_at;
		$r->started_at    = (string) $row->started_at;
		$r->finished_at   = (string) $row->finished_at;
		return $r;
	}

	/**
	 * Public representation for AJAX.
	 *
	 * @return array<string,mixed>
	 */
	public function to_public(): array {
		return array(
			'id'          => $this->id,
			'uuid'        => $this->uuid,
			'source_id'   => $this->source_id,
			'mode'        => $this->mode,
			'status'      => $this->status,
			'phase'       => $this->phase,
			'total'       => $this->total,
			'processed'   => $this->processed,
			'updated'     => $this->updated,
			'unchanged'   => $this->unchanged,
			'skipped'     => $this->skipped,
			'failed'      => $this->failed,
			'percent'     => $this->percent(),
			'active'      => $this->is_active(),
			'message'     => $this->message,
			'started_at'  => $this->started_at,
			'finished_at' => $this->finished_at,
		);
	}
}
