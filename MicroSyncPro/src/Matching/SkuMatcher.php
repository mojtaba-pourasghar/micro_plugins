<?php
/**
 * SKU matching.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Matching;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves SKUs to product/variation IDs in bulk (read-only query, prepared).
 * Matching is exact (byte-for-byte after trimming the incoming value), and a
 * SKU that resolves to more than one product is reported as ambiguous.
 */
class SkuMatcher {

	const CHUNK = 200;

	/**
	 * Resolve SKUs.
	 *
	 * @param string[] $skus SKUs.
	 * @return array<string,int[]> sku => product ids (empty array = unmatched).
	 */
	public function match( array $skus ): array {
		global $wpdb;
		$skus = array_values(
			array_unique(
				array_filter(
					array_map( static function ( $s ) { return trim( (string) $s ); }, $skus ),
					'strlen'
				)
			)
		);
		$result = array();
		foreach ( $skus as $sku ) {
			$result[ $sku ] = array();
		}
		foreach ( array_chunk( $skus, self::CHUNK ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- IN list of %s placeholders; SKUs are bound by prepare().
			$sql  = "SELECT pm.post_id, pm.meta_value
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				LEFT JOIN {$wpdb->posts} parent ON parent.ID = p.post_parent
				WHERE pm.meta_key = '_sku'
				AND pm.meta_value IN ({$placeholders})
				AND p.post_type IN ('product','product_variation')
				AND p.post_status NOT IN ('trash','auto-draft')
				AND ( p.post_type = 'product' OR ( parent.ID IS NOT NULL AND parent.post_status NOT IN ('trash','auto-draft') ) )";
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $chunk ) );
			// phpcs:enable
			foreach ( (array) $rows as $row ) {
				$value = (string) $row->meta_value;
				// Enforce exact (case- and accent-sensitive) equality on top of SQL collation.
				if ( isset( $result[ $value ] ) ) {
					$id = (int) $row->post_id;
					if ( ! in_array( $id, $result[ $value ], true ) ) {
						$result[ $value ][] = $id;
					}
				}
			}
		}
		return $result;
	}
}
