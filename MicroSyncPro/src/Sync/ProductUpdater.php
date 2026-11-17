<?php
/**
 * Applies one record to one WooCommerce product through the CRUD API.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Sync;

use MicroStudio\MicroSyncPro\Pricing\PricingEngine;
use MicroStudio\MicroSyncPro\Settings\Settings;
use MicroStudio\MicroSyncPro\Validation\RecordValidator;

defined( 'ABSPATH' ) || exit;

/**
 * Validates a record, computes the field changes against the current product,
 * and (when applying) uses WooCommerce setters + save(). Only fields present in
 * the record (= enabled in the mapping) are considered.
 */
class ProductUpdater {

	const STATUS_UPDATED      = 'updated';
	const STATUS_WOULD_UPDATE = 'would_update';
	const STATUS_UNCHANGED    = 'unchanged';
	const STATUS_SKIPPED      = 'skipped';
	const STATUS_FAILED       = 'failed';

	/** @var RecordValidator */
	private $validator;
	/** @var PricingEngine */
	private $pricing;
	/** @var Settings */
	private $settings;
	/** @var array<int,int[]> Parent category cache. */
	private $parent_cats = array();

	/**
	 * Constructor.
	 *
	 * @param RecordValidator $validator Validator.
	 * @param PricingEngine   $pricing   Pricing.
	 * @param Settings        $settings  Settings.
	 */
	public function __construct( RecordValidator $validator, PricingEngine $pricing, Settings $settings ) {
		$this->validator = $validator;
		$this->pricing   = $pricing;
		$this->settings  = $settings;
	}

	/**
	 * Supported product types.
	 *
	 * @return string[]
	 */
	public static function supported_types(): array {
		/**
		 * Filter product types that may be synchronized.
		 *
		 * @param string[] $types Types.
		 */
		return (array) apply_filters( 'micro_sync_pro_supported_product_types', array( 'simple', 'variable', 'variation', 'external' ) );
	}

	/**
	 * Process one record.
	 *
	 * @param \WC_Product          $product Product.
	 * @param array<string,string> $data    Mapped record (field => raw value).
	 * @param array<string,mixed>  $opts    Source processing options.
	 * @param bool                 $apply   Save changes (false = dry run).
	 * @return array{status:string,code:string,changes:array<string,mixed>,message:string}
	 */
	public function process( \WC_Product $product, array $data, array $opts, bool $apply ): array {
		$type = $product->get_type();
		if ( ! in_array( $type, self::supported_types(), true ) ) {
			return self::result(
				self::STATUS_SKIPPED,
				'unsupported_type',
				array(),
				/* translators: %s: product type. */
				sprintf( __( 'Product type "%s" is not supported.', 'micro-sync-pro' ), $type )
			);
		}

		$decimal = isset( $opts['decimal_separator'] ) ? (string) $opts['decimal_separator'] : '.';
		$notes   = array();
		$errors  = array();
		$values  = array();

		// 1) Validate every provided value before touching the product.
		foreach ( array( 'regular_price', 'sale_price' ) as $f ) {
			if ( ! array_key_exists( $f, $data ) ) {
				continue;
			}
			$raw = trim( (string) $data[ $f ] );
			if ( '' === $raw ) {
				if ( 'sale_price' === $f && ! empty( $opts['clear_sale_when_empty'] ) ) {
					$values[ $f ] = '';
				}
				continue;
			}
			$r = $this->validator->price( $raw, $decimal );
			if ( $r['ok'] ) {
				$values[ $f ] = $r['value'];
			} else {
				$errors[] = $r['error'];
			}
		}
		if ( array_key_exists( 'stock_quantity', $data ) && '' !== trim( (string) $data['stock_quantity'] ) ) {
			$r = $this->validator->stock( (string) $data['stock_quantity'] );
			if ( $r['ok'] ) {
				$values['stock_quantity'] = $r['value'];
			} else {
				$errors[] = $r['error'];
			}
		}
		if ( array_key_exists( 'stock_status', $data ) && '' !== trim( (string) $data['stock_status'] ) ) {
			$r = $this->validator->stock_status( (string) $data['stock_status'] );
			if ( $r['ok'] ) {
				$values['stock_status'] = $r['value'];
			} else {
				$errors[] = $r['error'];
			}
		}
		if ( ! empty( $errors ) ) {
			return self::result( self::STATUS_FAILED, 'invalid_value', array(), implode( ' ', $errors ) );
		}

		// 2) Prices.
		$has_price = array_key_exists( 'regular_price', $values ) || array_key_exists( 'sale_price', $values );
		if ( $has_price && ( 'variable' === $type || $product->is_type( 'grouped' ) ) ) {
			$notes[] = __( 'Prices of variable products are stored on their variations; price fields were ignored.', 'micro-sync-pro' );
			unset( $values['regular_price'], $values['sale_price'] );
			$has_price = false;
		}
		$target = array();
		if ( $has_price ) {
			$rule       = $this->pricing->rule_for( $this->category_ids( $product ) );
			$multiplier = isset( $opts['price_multiplier'] ) ? (float) $opts['price_multiplier'] : 1.0;
			if ( array_key_exists( 'regular_price', $values ) ) {
				$calc = $this->pricing->calculate( (float) $values['regular_price'], $rule, $multiplier );
				if ( $calc['ok'] ) {
					$target['regular_price'] = self::price_string( $calc['price'] );
				}
				if ( '' !== $calc['note'] ) {
					$notes[] = $calc['note'];
				}
			}
			if ( array_key_exists( 'sale_price', $values ) ) {
				if ( '' === $values['sale_price'] ) {
					$target['sale_price'] = '';
				} else {
					if ( $this->settings->get( 'apply_pricing_to_sale' ) ) {
						$calc = $this->pricing->calculate( (float) $values['sale_price'], $rule, $multiplier );
					} else {
						$calc = array(
							'ok'    => true,
							'price' => $this->pricing->round( (float) $values['sale_price'] * ( $multiplier > 0 ? $multiplier : 1 ) ),
							'note'  => '',
						);
					}
					if ( '' !== $calc['note'] ) {
						$notes[] = $calc['note'];
					}
					if ( $calc['ok'] ) {
						$regular = $target['regular_price'] ?? (string) $product->get_regular_price( 'edit' );
						if ( '' !== $regular && (float) $calc['price'] >= (float) $regular ) {
							$notes[] = __( 'Sale price is not lower than the regular price; sale price was ignored.', 'micro-sync-pro' );
						} else {
							$target['sale_price'] = self::price_string( $calc['price'] );
						}
					}
				}
			}
		}

		// 3) Stock.
		$global_stock = 'yes' === get_option( 'woocommerce_manage_stock', 'yes' );
		$manages      = (bool) $product->get_manage_stock( 'edit' );
		$enable_mgmt  = false;
		if ( array_key_exists( 'stock_quantity', $values ) ) {
			if ( 'external' === $type ) {
				$notes[] = __( 'External products do not have stock; quantity was ignored.', 'micro-sync-pro' );
			} elseif ( ! $global_stock ) {
				$notes[] = __( 'Stock management is disabled in WooCommerce settings; quantity was ignored.', 'micro-sync-pro' );
			} elseif ( ! $manages && empty( $opts['enable_stock_management'] ) ) {
				if ( 'variation' === $type && 'parent' === $product->get_manage_stock( 'view' ) ) {
					$notes[] = __( 'Stock of this variation is managed by the parent product; quantity was ignored.', 'micro-sync-pro' );
				} else {
					$notes[] = __( 'Stock management is not enabled for this product; quantity was ignored.', 'micro-sync-pro' );
				}
			} else {
				if ( ! $manages ) {
					$enable_mgmt = true;
				}
				$target['stock_quantity'] = (int) $values['stock_quantity'];
			}
		}
		if ( array_key_exists( 'stock_status', $values ) ) {
			if ( 'external' === $type ) {
				$notes[] = __( 'External products are always in stock; stock status was ignored.', 'micro-sync-pro' );
			} elseif ( 'variable' === $type ) {
				$notes[] = __( 'The stock status of variable products is derived from their variations; it was ignored.', 'micro-sync-pro' );
			} elseif ( $global_stock && ( $manages || $enable_mgmt ) ) {
				$notes[] = __( 'Stock status is calculated by WooCommerce from the managed quantity; it was ignored.', 'micro-sync-pro' );
			} else {
				$target['stock_status'] = (string) $values['stock_status'];
			}
		}

		// 4) Diff against current values.
		$changes = array();
		if ( $enable_mgmt ) {
			$changes['manage_stock'] = array(
				'before' => false,
				'after'  => true,
			);
		}
		foreach ( array( 'regular_price', 'sale_price' ) as $f ) {
			if ( ! array_key_exists( $f, $target ) ) {
				continue;
			}
			$before = (string) ( 'regular_price' === $f ? $product->get_regular_price( 'edit' ) : $product->get_sale_price( 'edit' ) );
			if ( ! self::same_price( $before, $target[ $f ] ) ) {
				$changes[ $f ] = array(
					'before' => $before,
					'after'  => $target[ $f ],
				);
			}
		}
		if ( array_key_exists( 'stock_quantity', $target ) ) {
			$before = $product->get_stock_quantity( 'edit' );
			if ( $enable_mgmt || null === $before || (int) $before !== $target['stock_quantity'] ) {
				$changes['stock_quantity'] = array(
					'before' => null === $before ? null : (int) $before,
					'after'  => $target['stock_quantity'],
				);
			}
		}
		if ( array_key_exists( 'stock_status', $target ) ) {
			$before = (string) $product->get_stock_status( 'edit' );
			if ( $before !== $target['stock_status'] ) {
				$changes['stock_status'] = array(
					'before' => $before,
					'after'  => $target['stock_status'],
				);
			}
		}

		$message = implode( ' ', $notes );
		if ( empty( $changes ) ) {
			return self::result( self::STATUS_UNCHANGED, empty( $notes ) ? 'no_changes' : 'no_changes_notes', array(), $message );
		}
		if ( ! $apply ) {
			return self::result( self::STATUS_WOULD_UPDATE, 'preview', $changes, $message );
		}

		try {
			self::apply_changes( $product, $changes );
			$product->save();
		} catch ( \Exception $e ) {
			return self::result(
				self::STATUS_FAILED,
				'save_error',
				array(),
				/* translators: %s: error message. */
				sprintf( __( 'WooCommerce rejected the update: %s', 'micro-sync-pro' ), wp_strip_all_tags( $e->getMessage() ) )
			);
		}
		// Record the values actually stored (WooCommerce may adjust e.g. stock status).
		foreach ( $changes as $f => $c ) {
			$changes[ $f ]['after'] = self::read_field( $product, $f );
		}
		if ( isset( $changes['stock_quantity'] ) || isset( $changes['manage_stock'] ) ) {
			$changes['stock_status_after_save'] = (string) $product->get_stock_status( 'edit' );
		}
		return self::result( self::STATUS_UPDATED, 'updated', $changes, $message );
	}

	/**
	 * Apply a change set with WooCommerce setters (no save).
	 *
	 * @param \WC_Product         $product Product.
	 * @param array<string,mixed> $changes field => [before, after].
	 * @param string              $which   'after' (sync) or 'before' (rollback).
	 * @throws \WC_Data_Exception On invalid data.
	 */
	public static function apply_changes( \WC_Product $product, array $changes, string $which = 'after' ): void {
		if ( isset( $changes['manage_stock'] ) ) {
			$product->set_manage_stock( (bool) $changes['manage_stock'][ $which ] );
		}
		if ( isset( $changes['regular_price'] ) ) {
			$product->set_regular_price( (string) $changes['regular_price'][ $which ] );
		}
		if ( isset( $changes['sale_price'] ) ) {
			$product->set_sale_price( (string) $changes['sale_price'][ $which ] );
		}
		if ( isset( $changes['stock_quantity'] ) && null !== $changes['stock_quantity'][ $which ] ) {
			$product->set_stock_quantity( (int) $changes['stock_quantity'][ $which ] );
		}
		if ( isset( $changes['stock_status'] ) ) {
			$product->set_stock_status( (string) $changes['stock_status'][ $which ] );
		}
	}

	/**
	 * Read a field's current value in change-set format.
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $field   Field.
	 * @return mixed
	 */
	public static function read_field( \WC_Product $product, string $field ) {
		switch ( $field ) {
			case 'manage_stock':
				return (bool) $product->get_manage_stock( 'edit' );
			case 'regular_price':
				return (string) $product->get_regular_price( 'edit' );
			case 'sale_price':
				return (string) $product->get_sale_price( 'edit' );
			case 'stock_quantity':
				$q = $product->get_stock_quantity( 'edit' );
				return null === $q ? null : (int) $q;
			case 'stock_status':
				return (string) $product->get_stock_status( 'edit' );
		}
		return null;
	}

	/**
	 * Compare prices numerically ('' only equals '').
	 *
	 * @param string $a A.
	 * @param string $b B.
	 */
	public static function same_price( string $a, string $b ): bool {
		if ( '' === $a || '' === $b ) {
			return $a === $b;
		}
		return abs( (float) $a - (float) $b ) < 0.000001;
	}

	/**
	 * Format a price for storage.
	 *
	 * @param float $price Price.
	 */
	private static function price_string( float $price ): string {
		return (string) wc_format_decimal( $price, wc_get_price_decimals() );
	}

	/**
	 * Category ids used for pricing rules (variations inherit the parent's).
	 *
	 * @param \WC_Product $product Product.
	 * @return int[]
	 */
	private function category_ids( \WC_Product $product ): array {
		if ( $product->is_type( 'variation' ) ) {
			$parent_id = (int) $product->get_parent_id();
			if ( ! isset( $this->parent_cats[ $parent_id ] ) ) {
				$parent                          = wc_get_product( $parent_id );
				$this->parent_cats[ $parent_id ] = $parent ? array_map( 'intval', $parent->get_category_ids( 'edit' ) ) : array();
				if ( count( $this->parent_cats ) > 500 ) {
					$this->parent_cats = array_slice( $this->parent_cats, -250, null, true );
				}
			}
			return $this->parent_cats[ $parent_id ];
		}
		return array_map( 'intval', $product->get_category_ids( 'edit' ) );
	}

	/**
	 * Build a result array.
	 *
	 * @param string              $status  Status.
	 * @param string              $code    Code.
	 * @param array<string,mixed> $changes Changes.
	 * @param string              $message Message.
	 * @return array{status:string,code:string,changes:array<string,mixed>,message:string}
	 */
	private static function result( string $status, string $code, array $changes, string $message ): array {
		return compact( 'status', 'code', 'changes', 'message' );
	}
}
