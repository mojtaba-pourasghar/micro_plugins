<?php
/**
 * Field mapping between source columns and WooCommerce fields.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Mapping;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Defines the synchronizable WooCommerce fields, validates mappings and
 * extracts mapped values from source rows.
 */
class FieldMapper {

	const SKU = 'sku';

	/** Synchronizable fields (the key is the internal id). */
	const FIELDS = array( 'sku', 'regular_price', 'sale_price', 'stock_quantity', 'stock_status' );

	/**
	 * Human-readable labels.
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		return array(
			'sku'            => __( 'SKU (matching key)', 'micro-sync-pro' ),
			'regular_price'  => __( 'Regular price', 'micro-sync-pro' ),
			'sale_price'     => __( 'Sale price', 'micro-sync-pro' ),
			'stock_quantity' => __( 'Stock quantity', 'micro-sync-pro' ),
			'stock_status'   => __( 'Stock status', 'micro-sync-pro' ),
		);
	}

	/**
	 * Header-name hints used to suggest a mapping.
	 *
	 * @return array<string,string[]>
	 */
	private static function hints(): array {
		return array(
			'sku'            => array( 'sku', 'code', 'product_code', 'item_code', 'barcode', 'کد', 'کد کالا', 'کد محصول', 'شناسه' ),
			'regular_price'  => array( 'price', 'regular_price', 'regular price', 'base_price', 'قیمت', 'قیمت فروش', 'قیمت عادی' ),
			'sale_price'     => array( 'sale_price', 'sale price', 'special_price', 'discount_price', 'قیمت ویژه', 'قیمت تخفیف' ),
			'stock_quantity' => array( 'stock', 'qty', 'quantity', 'stock_quantity', 'inventory', 'موجودی', 'تعداد' ),
			'stock_status'   => array( 'stock_status', 'availability', 'status', 'وضعیت', 'وضعیت موجودی' ),
		);
	}

	/**
	 * Suggest a mapping based on column names.
	 *
	 * @param string[] $columns Columns.
	 * @return array<string,array{column:string,enabled:bool}>
	 */
	public function suggest( array $columns ): array {
		$map   = array();
		$lower = array();
		foreach ( $columns as $c ) {
			$lower[ function_exists( 'mb_strtolower' ) ? mb_strtolower( $c ) : strtolower( $c ) ] = $c;
		}
		foreach ( self::hints() as $field => $names ) {
			$map[ $field ] = array(
				'column'  => '',
				'enabled' => false,
			);
			foreach ( $names as $n ) {
				if ( isset( $lower[ $n ] ) ) {
					$map[ $field ] = array(
						'column'  => $lower[ $n ],
						'enabled' => true,
					);
					break;
				}
			}
		}
		return $map;
	}

	/**
	 * Validate and normalize a submitted mapping.
	 *
	 * @param mixed    $input   Raw input: field => [column, enabled].
	 * @param string[] $columns Known columns (server-side detected). Empty = accept any safe name.
	 * @return array<string,array{column:string,enabled:bool}>|WP_Error
	 */
	public function sanitize( $input, array $columns ) {
		if ( ! is_array( $input ) ) {
			return new WP_Error( 'msp_mapping_invalid', __( 'The field mapping is invalid.', 'micro-sync-pro' ) );
		}
		$known = array_flip( $columns );
		$out   = array();
		foreach ( self::FIELDS as $field ) {
			$row     = isset( $input[ $field ] ) && is_array( $input[ $field ] ) ? $input[ $field ] : array();
			$column  = isset( $row['column'] ) && is_scalar( $row['column'] ) ? trim( wp_unslash( (string) $row['column'] ) ) : '';
			$column  = \MicroStudio\MicroSyncPro\Parser\HeaderNormalizer::clean( $column );
			$enabled = self::SKU === $field ? true : ! empty( $row['enabled'] );
			if ( '' !== $column && ! empty( $columns ) && ! isset( $known[ $column ] ) ) {
				return new WP_Error(
					'msp_mapping_column',
					sprintf(
						/* translators: %s: column name. */
						__( 'The column "%s" does not exist in the source.', 'micro-sync-pro' ),
						$column
					)
				);
			}
			if ( $enabled && '' === $column ) {
				if ( self::SKU === $field ) {
					return new WP_Error( 'msp_mapping_sku', __( 'A source column must be mapped to SKU.', 'micro-sync-pro' ) );
				}
				$enabled = false;
			}
			$out[ $field ] = array(
				'column'  => $column,
				'enabled' => $enabled,
			);
		}
		$synced = array_filter(
			$out,
			static function ( $m, $f ) {
				return self::SKU !== $f && $m['enabled'];
			},
			ARRAY_FILTER_USE_BOTH
		);
		if ( empty( $synced ) ) {
			return new WP_Error( 'msp_mapping_empty', __( 'Select at least one field to synchronize besides SKU.', 'micro-sync-pro' ) );
		}
		return $out;
	}

	/**
	 * Whether a mapping is usable for a run.
	 *
	 * @param array<string,mixed> $mapping Mapping.
	 */
	public function is_complete( array $mapping ): bool {
		if ( empty( $mapping['sku']['column'] ) ) {
			return false;
		}
		foreach ( self::enabled_fields( $mapping ) as $f ) {
			if ( self::SKU !== $f ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Enabled fields (including sku).
	 *
	 * @param array<string,mixed> $mapping Mapping.
	 * @return string[]
	 */
	public static function enabled_fields( array $mapping ): array {
		$out = array();
		foreach ( self::FIELDS as $f ) {
			if ( ! empty( $mapping[ $f ]['enabled'] ) && ! empty( $mapping[ $f ]['column'] ) ) {
				$out[] = $f;
			}
		}
		return $out;
	}

	/**
	 * Columns required by the mapping that are missing in a header list.
	 *
	 * @param array<string,mixed> $mapping Mapping.
	 * @param string[]            $headers Headers.
	 * @return string[]
	 */
	public function missing_columns( array $mapping, array $headers ): array {
		$have    = array_flip( $headers );
		$missing = array();
		foreach ( self::enabled_fields( $mapping ) as $f ) {
			$col = (string) $mapping[ $f ]['column'];
			if ( ! isset( $have[ $col ] ) ) {
				$missing[] = $col;
			}
		}
		return array_values( array_unique( $missing ) );
	}

	/**
	 * Extract enabled fields from a parsed row.
	 *
	 * @param array<string,string> $row     Row.
	 * @param array<string,mixed>  $mapping Mapping.
	 * @return array<string,string>
	 */
	public function extract( array $row, array $mapping ): array {
		$out = array();
		foreach ( self::enabled_fields( $mapping ) as $f ) {
			$col       = (string) $mapping[ $f ]['column'];
			$out[ $f ] = isset( $row[ $col ] ) ? (string) $row[ $col ] : '';
		}
		return $out;
	}
}
