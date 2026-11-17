<?php
/**
 * Log persistence.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Repository;

use MicroStudio\MicroSyncPro\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Log storage.
 */
class LogRepository {

	const LEVELS = array( 'debug', 'info', 'warning', 'error' );

	/**
	 * Insert a log row.
	 *
	 * @param string              $level   Level.
	 * @param string              $message Message (already redacted).
	 * @param array<string,mixed> $context Context (already redacted).
	 * @param int|null            $run_id  Run.
	 */
	public function insert( string $level, string $message, array $context, ?int $run_id ): void {
		global $wpdb;
		$wpdb->insert(
			Schema::table( 'logs' ),
			array(
				'run_id'     => $run_id,
				'level'      => in_array( $level, self::LEVELS, true ) ? $level : 'info',
				'message'    => mb_substr( $message, 0, 2000 ),
				'context'    => empty( $context ) ? null : wp_json_encode( $context ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Paginated logs.
	 *
	 * @param array<string,mixed> $filters  level, run_id, search.
	 * @param int                 $page     Page.
	 * @param int                 $per_page Per page.
	 * @return array{items:array<int,object>,total:int}
	 */
	public function paginate( array $filters, int $page, int $per_page ): array {
		global $wpdb;
		$table  = Schema::table( 'logs' );
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $filters['level'] ) && in_array( $filters['level'], self::LEVELS, true ) ) {
			$where[]  = 'level = %s';
			$params[] = $filters['level'];
		}
		if ( ! empty( $filters['run_id'] ) ) {
			$where[]  = 'run_id = %d';
			$params[] = (int) $filters['run_id'];
		}
		if ( isset( $filters['search'] ) && '' !== $filters['search'] ) {
			$where[]  = 'message LIKE %s';
			$params[] = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
		}
		$w      = implode( ' AND ', $where );
		$offset = max( 0, ( $page - 1 ) * $per_page );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- WHERE clauses/IN lists are built from fixed fragments; every value is bound by prepare().
		$count = "SELECT COUNT(*) FROM {$table} WHERE {$w}";
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count, $params ) ) : $wpdb->get_var( $count ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$w} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, $offset ) ) ) );
		// phpcs:enable
		return array(
			'items' => (array) $rows,
			'total' => $total,
		);
	}

	/**
	 * Delete logs older than N days.
	 *
	 * @param int $days Days.
	 */
	public function purge_older_than( int $days ): int {
		global $wpdb;
		$table  = Schema::table( 'logs' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
