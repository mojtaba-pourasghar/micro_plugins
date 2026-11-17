<?php
/**
 * Run persistence.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Repository;

use MicroStudio\MicroSyncPro\Database\Schema;
use MicroStudio\MicroSyncPro\Sync\Run;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD, locking and statistics for runs.
 */
class RunRepository {

	/** Column map for updates: property => [column, format]. */
	const COLUMNS = array(
		'status'       => array( 'status', '%s' ),
		'phase'        => array( 'phase', '%s' ),
		'total'        => array( 'total_records', '%d' ),
		'processed'    => array( 'processed', '%d' ),
		'updated'      => array( 'updated_count', '%d' ),
		'unchanged'    => array( 'unchanged_count', '%d' ),
		'skipped'      => array( 'skipped_count', '%d' ),
		'failed'       => array( 'failed_count', '%d' ),
		'cursor'       => array( 'cursor_pos', '%d' ),
		'staged_file'  => array( 'staged_file', '%s' ),
		'message'      => array( 'message', '%s' ),
		'attempts'     => array( 'attempts', '%d' ),
		'heartbeat_at' => array( 'heartbeat_at', '%s' ),
		'started_at'   => array( 'started_at', '%s' ),
		'finished_at'  => array( 'finished_at', '%s' ),
	);

	/**
	 * Table.
	 */
	private function table(): string {
		return Schema::table( 'runs' );
	}

	/**
	 * Create a run.
	 *
	 * @param array<string,mixed> $data Data: source_id, mode, trigger, parent_run_id, user_id, snapshot, staged_file.
	 */
	public function create( array $data ): ?Run {
		global $wpdb;
		$row = array(
			'uuid'              => wp_generate_uuid4(),
			'source_id'         => (int) ( $data['source_id'] ?? 0 ),
			'parent_run_id'     => ! empty( $data['parent_run_id'] ) ? (int) $data['parent_run_id'] : null,
			'mode'              => in_array( $data['mode'] ?? '', array( Run::MODE_DRY_RUN, Run::MODE_APPLY, Run::MODE_ROLLBACK ), true ) ? $data['mode'] : Run::MODE_DRY_RUN,
			'run_trigger'       => in_array( $data['trigger'] ?? '', array( 'manual', 'schedule', 'cli' ), true ) ? $data['trigger'] : 'manual',
			'status'            => Run::STATUS_QUEUED,
			'phase'             => (string) ( $data['phase'] ?? Run::PHASE_PREPARE ),
			'staged_file'       => (string) ( $data['staged_file'] ?? '' ),
			'total_records'     => (int) ( $data['total'] ?? 0 ),
			'settings_snapshot' => wp_json_encode( $data['snapshot'] ?? array() ),
			'user_id'           => (int) ( $data['user_id'] ?? 0 ),
			'created_at'        => current_time( 'mysql', true ),
			'heartbeat_at'      => current_time( 'mysql', true ),
		);
		if ( ! $wpdb->insert( $this->table(), $row ) ) {
			return null;
		}
		return $this->find( (int) $wpdb->insert_id );
	}

	/**
	 * Find by id.
	 *
	 * @param int $id Id.
	 */
	public function find( int $id ): ?Run {
		global $wpdb;
		$table = $this->table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? Run::from_row( $row ) : null;
	}

	/**
	 * Update fields.
	 *
	 * @param int                 $id     Id.
	 * @param array<string,mixed> $fields Property => value.
	 */
	public function update( int $id, array $fields ): bool {
		global $wpdb;
		$data   = array();
		$format = array();
		foreach ( $fields as $prop => $value ) {
			if ( 'snapshot' === $prop ) {
				$data['settings_snapshot'] = wp_json_encode( $value );
				$format[]                  = '%s';
				continue;
			}
			if ( ! isset( self::COLUMNS[ $prop ] ) ) {
				continue;
			}
			list( $col, $fmt ) = self::COLUMNS[ $prop ];
			$data[ $col ]      = $value;
			$format[]          = $fmt;
		}
		if ( empty( $data ) ) {
			return false;
		}
		return false !== $wpdb->update( $this->table(), $data, array( 'id' => $id ), $format, array( '%d' ) );
	}

	/**
	 * Atomically change status only if the current status matches.
	 *
	 * @param int      $id   Id.
	 * @param string[] $from Allowed current statuses.
	 * @param string   $to   New status.
	 */
	public function transition( int $id, array $from, string $to ): bool {
		global $wpdb;
		$table = $this->table();
		$in    = implode( ',', array_fill( 0, count( $from ), '%s' ) );
		$sql   = "UPDATE {$table} SET status = %s WHERE id = %d AND status IN ({$in})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$res   = $wpdb->query( $wpdb->prepare( $sql, array_merge( array( $to, $id ), $from ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $res > 0;
	}

	/**
	 * Active run for a source (oldest first).
	 *
	 * @param int $source_id Source.
	 */
	public function active_for_source( int $source_id ): ?Run {
		global $wpdb;
		$table = $this->table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE source_id = %d AND status IN ('queued','running','cancelling') ORDER BY id ASC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$source_id
			)
		);
		return $row ? Run::from_row( $row ) : null;
	}

	/**
	 * All active runs.
	 *
	 * @return Run[]
	 */
	public function active(): array {
		global $wpdb;
		$table = $this->table();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE status IN ('queued','running','cancelling') ORDER BY id ASC LIMIT 50" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( Run::class, 'from_row' ), (array) $rows );
	}

	/**
	 * Claim the processing lock (atomic compare-and-set).
	 *
	 * @param int    $id    Run.
	 * @param string $token Token.
	 * @param int    $ttl   Seconds.
	 */
	public function claim_lock( int $id, string $token, int $ttl ): bool {
		global $wpdb;
		$table   = $this->table();
		$now     = current_time( 'mysql', true );
		$expires = gmdate( 'Y-m-d H:i:s', time() + $ttl );
		$res     = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET lock_token = %s, lock_expires = %s, heartbeat_at = %s WHERE id = %d AND status IN ('queued','running','cancelling') AND ( lock_token IS NULL OR lock_expires IS NULL OR lock_expires < %s )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$token,
				$expires,
				$now,
				$id,
				$now
			)
		);
		return $res > 0;
	}

	/**
	 * Extend the lock and heartbeat.
	 *
	 * @param int    $id    Run.
	 * @param string $token Token.
	 * @param int    $ttl   Seconds.
	 */
	public function touch_lock( int $id, string $token, int $ttl ): void {
		global $wpdb;
		$wpdb->update(
			$this->table(),
			array(
				'lock_expires' => gmdate( 'Y-m-d H:i:s', time() + $ttl ),
				'heartbeat_at' => current_time( 'mysql', true ),
			),
			array(
				'id'         => $id,
				'lock_token' => $token,
			)
		);
	}

	/**
	 * Release the lock.
	 *
	 * @param int    $id    Run.
	 * @param string $token Token.
	 */
	public function release_lock( int $id, string $token ): void {
		global $wpdb;
		$table = $this->table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET lock_token = NULL, lock_expires = NULL WHERE id = %d AND lock_token = %s", $id, $token ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Whether the run is currently locked by someone.
	 *
	 * @param int $id Run.
	 */
	public function is_locked( int $id ): bool {
		global $wpdb;
		$table = $this->table();
		$v     = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id = %d AND lock_token IS NOT NULL AND lock_expires >= %s", $id, current_time( 'mysql', true ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $v > 0;
	}

	/**
	 * Active runs whose heartbeat is older than $minutes and that are not locked.
	 *
	 * @param int $minutes Minutes.
	 * @return Run[]
	 */
	public function stale( int $minutes ): array {
		global $wpdb;
		$table  = $this->table();
		$now    = current_time( 'mysql', true );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $minutes * MINUTE_IN_SECONDS );
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status IN ('queued','running','cancelling') AND ( heartbeat_at IS NULL OR heartbeat_at < %s ) AND ( lock_expires IS NULL OR lock_expires < %s ) ORDER BY id ASC LIMIT 20", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff,
				$now
			)
		);
		return array_map( array( Run::class, 'from_row' ), (array) $rows );
	}

	/**
	 * Recalculate counters from items (source of truth, safe after resume).
	 *
	 * @param int $id Run.
	 * @return array{processed:int,updated:int,unchanged:int,skipped:int,failed:int}
	 */
	public function recount( int $id ): array {
		global $wpdb;
		$items = Schema::table( 'items' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS c FROM {$items} WHERE run_id = %d GROUP BY status", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$c     = array(
			'processed' => 0,
			'updated'   => 0,
			'unchanged' => 0,
			'skipped'   => 0,
			'failed'    => 0,
		);
		foreach ( (array) $rows as $row ) {
			$n               = (int) $row->c;
			$c['processed'] += $n;
			switch ( $row->status ) {
				case 'updated':
				case 'would_update':
				case 'rolled_back':
					$c['updated'] += $n;
					break;
				case 'unchanged':
					$c['unchanged'] += $n;
					break;
				case 'failed':
					$c['failed'] += $n;
					break;
				default:
					$c['skipped'] += $n;
			}
		}
		$this->update( $id, $c );
		return $c;
	}

	/**
	 * Paginated list.
	 *
	 * @param array<string,mixed> $filters  source_id, status, mode.
	 * @param int                 $page     Page.
	 * @param int                 $per_page Per page.
	 * @return array{items:Run[],total:int}
	 */
	public function paginate( array $filters, int $page, int $per_page ): array {
		global $wpdb;
		$table  = $this->table();
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $filters['source_id'] ) ) {
			$where[]  = 'source_id = %d';
			$params[] = (int) $filters['source_id'];
		}
		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = (string) $filters['status'];
		}
		if ( ! empty( $filters['mode'] ) ) {
			$where[]  = 'mode = %s';
			$params[] = (string) $filters['mode'];
		}
		$w      = implode( ' AND ', $where );
		$offset = max( 0, ( $page - 1 ) * $per_page );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- WHERE clauses/IN lists are built from fixed fragments; every value is bound by prepare().
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$w}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
		$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$w} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, $offset ) ) ) );
		// phpcs:enable
		return array(
			'items' => array_map( array( Run::class, 'from_row' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Aggregate stats for the dashboard.
	 *
	 * @param int $days Days.
	 * @return array<string,int>
	 */
	public function stats( int $days ): array {
		global $wpdb;
		$table = $this->table();
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS runs, SUM(status = 'completed') AS completed, SUM(status IN ('failed','interrupted')) AS failed_runs, SUM(CASE WHEN mode = 'apply' THEN updated_count ELSE 0 END) AS updated, SUM(CASE WHEN mode = 'apply' THEN failed_count ELSE 0 END) AS failed_records FROM {$table} WHERE created_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since
			),
			ARRAY_A
		);
		return array_map( 'intval', (array) $row );
	}

	/**
	 * Run ids (and staged files) older than a cutoff that are finished.
	 *
	 * @param int $days Days.
	 * @return Run[]
	 */
	public function finished_before( int $days ): array {
		global $wpdb;
		$table  = $this->table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE created_at < %s AND status NOT IN ('queued','running','cancelling') ORDER BY id ASC LIMIT 500", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( Run::class, 'from_row' ), (array) $rows );
	}

	/**
	 * Staged/dup file names referenced by runs that still need them.
	 *
	 * @return string[]
	 */
	public function referenced_files(): array {
		global $wpdb;
		$table = $this->table();
		$rows  = $wpdb->get_results( "SELECT staged_file, settings_snapshot FROM {$table} WHERE staged_file <> ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$files = array();
		foreach ( (array) $rows as $row ) {
			$files[] = (string) $row->staged_file;
			$snap    = json_decode( (string) $row->settings_snapshot, true );
			if ( is_array( $snap ) && ! empty( $snap['dup_file'] ) ) {
				$files[] = (string) $snap['dup_file'];
			}
		}
		return $files;
	}

	/**
	 * Delete runs (and their items/logs).
	 *
	 * @param int[] $ids Ids.
	 */
	public function delete_many( array $ids ): int {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$runs  = $this->table();
		$items = Schema::table( 'items' );
		$logs  = Schema::table( 'logs' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- WHERE clauses/IN lists are built from fixed fragments; every value is bound by prepare().
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$items} WHERE run_id IN ({$in})", $ids ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$logs} WHERE run_id IN ({$in})", $ids ) );
		$n = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$runs} WHERE id IN ({$in})", $ids ) );
		// phpcs:enable
		return $n;
	}
}
