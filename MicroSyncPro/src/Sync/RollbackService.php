<?php
/**
 * Rollback of applied changes.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Sync;

use MicroStudio\MicroSyncPro\Repository\ItemRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Restores the "before" values stored with each updated record. A product is
 * only reverted when its current values still equal what the sync wrote;
 * otherwise it was changed afterwards and is reported as a conflict.
 */
class RollbackService {

	/** @var ItemRepository */
	private $items;

	/**
	 * Constructor.
	 *
	 * @param ItemRepository $items Items.
	 */
	public function __construct( ItemRepository $items ) {
		$this->items = $items;
	}

	/**
	 * Revert one item.
	 *
	 * @param object $item Item row from the applied run.
	 * @return array<string,mixed>
	 */
	public function revert( $item ): array {
		$base    = array(
			'sku'        => (string) $item->sku,
			'product_id' => (int) $item->product_id,
		);
		$changes = json_decode( (string) $item->changes, true );
		$changes = is_array( $changes ) ? $changes : array();
		unset( $changes['stock_status_after_save'] );
		if ( empty( $changes ) ) {
			return $base + array(
				'status'  => ProductUpdater::STATUS_SKIPPED,
				'code'    => 'nothing_to_revert',
				'message' => __( 'No stored changes to revert.', 'micro-sync-pro' ),
			);
		}
		$product = wc_get_product( (int) $item->product_id );
		if ( ! $product instanceof \WC_Product ) {
			return $base + array(
				'status'  => ProductUpdater::STATUS_FAILED,
				'code'    => 'product_missing',
				'message' => __( 'The product no longer exists.', 'micro-sync-pro' ),
			);
		}
		$conflicts = array();
		foreach ( $changes as $field => $c ) {
			if ( ! is_array( $c ) || ! array_key_exists( 'after', $c ) ) {
				continue;
			}
			$current = ProductUpdater::read_field( $product, (string) $field );
			$same    = in_array( $field, array( 'regular_price', 'sale_price' ), true )
				? ProductUpdater::same_price( (string) $current, (string) $c['after'] )
				: ( (string) wp_json_encode( $current ) === (string) wp_json_encode( $c['after'] ) );
			if ( ! $same ) {
				$conflicts[] = $field;
			}
		}
		if ( ! empty( $conflicts ) ) {
			return $base + array(
				'status'  => ProductUpdater::STATUS_SKIPPED,
				'code'    => 'conflict',
				/* translators: %s: field names. */
				'message' => sprintf( __( 'The product was modified after synchronization (%s); it was not reverted.', 'micro-sync-pro' ), implode( ', ', $conflicts ) ),
			);
		}
		$reverse = array();
		foreach ( $changes as $field => $c ) {
			$reverse[ $field ] = array(
				'before' => $c['after'],
				'after'  => $c['before'],
			);
		}
		try {
			ProductUpdater::apply_changes( $product, $changes, 'before' );
			$product->save();
		} catch ( \Exception $e ) {
			return $base + array(
				'status'  => ProductUpdater::STATUS_FAILED,
				'code'    => 'save_error',
				'message' => wp_strip_all_tags( $e->getMessage() ),
			);
		}
		return $base + array(
			'status'  => 'rolled_back',
			'code'    => 'reverted',
			'changes' => $reverse,
			'message' => '',
		);
	}
}
