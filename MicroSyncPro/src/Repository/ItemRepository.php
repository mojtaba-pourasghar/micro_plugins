<?php
/**
 * Per-record result persistence.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Repository;

use MicroStudio\MicroSyncPro\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Stores one row per processed record (status, reason code, before/after
 * changes and message). The (run_id,row_num) unique key makes resumed batches
 * idempotent.
 */
class ItemRepository {

	/**
	 * Table.
	 */
	private function table(): string {
		return Schema::table( 'items' );
	}

	/**
	 * Insert a result unless it already exists for (run,row).
	 *
	 * @param int                 $run_id Run.
	 * @param int                 $row    Row number.
	 * @param array<string,mixed> $data   sku, product_id, status, code, changes, message.
	 */
	public function insert( int $run_id, int $row, array $data ): bool {
		global $wpdb;
		$table   = $this->table();
		$changes = empty( $data['changes'] ) ? null : wp_json_encode( $data['changes'] );
		$sku     = mb_substr( (string) ( $data['sku'] ?? '' ), 0, 191 );
		$message = mb_substr( (string) ( $data['message'] ?? '' ), 0, 2000 );
		$res     = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (run_id, row_num, sku, product_id, status, code, changes, message, created_at) VALUES (%d, %d, %s, %d, %s, %s, %s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$run_id,
				$row,
				$sku,
				(int) ( $data['product_id'] ?? 0 ),
				(string) ( $data['status'] ?? '' ),
				(string) ( $data['code'] ?? '' ),
				(string) $changes,
				$message,
				current_time( 'mysql', true )
			)
		);
		return $res > 0;
	}

	/**
	 * Row numbers already stored for a run among the given rows.
	 *
	 * @param int   $run_id Run.
	 * @param int[] $rows   Rows.
	 * @return array<int,bool>
	 */
	public function existing_rows( int $run_id, array $rows ): array {
		global $wpdb;
		$rows = array_values( array_filter( array_map( 'intval', $rows ), static function ( $r ) { return $r >= 0; } ) );
		if ( empty( $rows ) ) {
			return array();
		}
		$table = $this->table();
		$in    = implode( ',', array_fill( 0, count( $rows ), '%d' ) );
		$found = $wpdb->get_col( $wpdb->prepare( "SELECT row_num FROM {$table} WHERE run_id = %d AND row_num IN ({$in})", array_merge( array( $run_id ), $rows ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$out   = array();
		foreach ( (array) $found as $r ) {
			$out[ (int) $r ] = true;
		}
		return $out;
	}

	/**
	 * Updated items of a run after a given item id (for rollback batches).
	 *
	 * @param int $run_id  Run.
	 * @param int $after   Last processed item id.
	 * @param int $limit   Limit.
	 * @return array<int,object>
	 */
	public function updated_after( int $run_id, int $after, int $limit ): array {
		global $wpdb;
		$table = $this->table();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %d AND status = 'updated' AND id > %d ORDER BY id ASC LIMIT %d", $run_id, $after, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Count updated items.
	 *
	 * @param int $run_id Run.
	 */
	public function count_updated( int $run_id ): int {
		global $wpdb;
		$table = $this->table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE run_id = %d AND status = 'updated'", $run_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Paginated items.
	 *
	 * @param int                 $run_id   Run.
	 * @param array<string,mixed> $filters  status, code, search.
	 * @param int                 $page     Page.
	 * @param int                 $per_page Per page.
	 * @return array{items:array<int,object>,total:int}
	 */
	public function paginate( int $run_id, array $filters, int $page, int $per_page ): array {
		global $wpdb;
		$table  = $this->table();
		$where  = array( 'run_id = %d' );
		$params = array( $run_id );
		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = (string) $filters['status'];
		}
		if ( ! empty( $filters['code'] ) ) {
			$where[]  = 'code = %s';
			$params[] = (string) $filters['code'];
		}
		if ( isset( $filters['search'] ) && '' !== $filters['search'] ) {
			$where[]  = 'sku LIKE %s';
			$params[] = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
		}
		$w      = implode( ' AND ', $where );
		$offset = max( 0, ( $page - 1 ) * $per_page );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- WHERE clauses/IN lists are built from fixed fragments; every value is bound by prepare().
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$w}", $params ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$w} ORDER BY row_num ASC, id ASC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, $offset ) ) ) );
		// phpcs:enable
		return array(
			'items' => (array) $rows,
			'total' => $total,
		);
	}

	/**
	 * Counts by status and code for a run.
	 *
	 * @param int $run_id Run.
	 * @return array<int,object>
	 */
	public function breakdown( int $run_id ): array {
		global $wpdb;
		$table = $this->table();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT status, code, COUNT(*) AS c FROM {$table} WHERE run_id = %d GROUP BY status, code ORDER BY c DESC", $run_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Stream all items of a run (for CSV export) in chunks.
	 *
	 * @param int      $run_id   Run.
	 * @param callable $callback Called with each row.
	 */
	public function each( int $run_id, callable $callback ): void {
		global $wpdb;
		$table = $this->table();
		$last  = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %d AND id > %d ORDER BY id ASC LIMIT 500", $run_id, $last ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $rows as $row ) {
				$callback( $row );
				$last = (int) $row->id;
			}
		} while ( ! empty( $rows ) );
	}

	/**
	 * Recent product changes across apply runs (dashboard).
	 *
	 * @param int $limit Limit.
	 * @return array<int,object>
	 */
	public function recent_updates( int $limit ): array {
		global $wpdb;
		$table = $this->table();
		$runs  = Schema::table( 'runs' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT i.* FROM {$table} i INNER JOIN {$runs} r ON r.id = i.run_id WHERE r.mode = 'apply' AND i.status = 'updated' ORDER BY i.id DESC LIMIT %d", $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
