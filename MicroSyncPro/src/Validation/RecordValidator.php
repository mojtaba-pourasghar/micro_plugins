<?php
/**
 * Record value validation.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Validation;

use MicroStudio\MicroSyncPro\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes and validates incoming values: prices, stock quantities and
 * stock statuses. Handles Persian/Arabic digits and separators.
 */
class RecordValidator {

	const MAX_PRICE = 1000000000000.0;
	const MAX_STOCK = 1000000000;

	/** @var Settings */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Convert Persian and Arabic-Indic digits and separators to ASCII.
	 *
	 * @param string $value Value.
	 */
	public static function ascii_digits( string $value ): string {
		$map = array(
			'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
			'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
			'٫' => '.', '٬' => '', "\u{066C}" => '', "\u{00A0}" => '', "\u{202F}" => '', "\u{200C}" => '', "\u{200F}" => '', "\u{200E}" => '',
		);
		return strtr( $value, $map );
	}

	/**
	 * Parse a decimal number written by a human/supplier.
	 *
	 * @param string $value             Raw value.
	 * @param string $decimal_separator "." or ",".
	 * @return string|null Canonical "123.45" or null when invalid.
	 */
	public static function parse_decimal( string $value, string $decimal_separator = '.' ): ?string {
		$v = trim( self::ascii_digits( $value ) );
		// Strip common currency words/symbols around the number.
		$v = (string) preg_replace( '/^(?:[^\d\-+.,]{1,12})|(?:[^\d.,]{1,12})$/u', '', $v );
		$v = str_replace( array( ' ', "'", '_' ), '', $v );
		if ( '' === $v ) {
			return null;
		}
		if ( ',' === $decimal_separator ) {
			$v = str_replace( '.', '', $v );
			$v = str_replace( ',', '.', $v );
		} else {
			// Thousands separators must be well-formed (1,234,567.5).
			if ( false !== strpos( $v, ',' ) ) {
				if ( ! preg_match( '/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $v ) ) {
					return null;
				}
				$v = str_replace( ',', '', $v );
			}
		}
		if ( preg_match( '/^-?\d+(\.\d+)?$/', $v ) ) {
			return $v;
		}
		if ( preg_match( '/^-?\d+\.$/', $v ) ) {
			return rtrim( $v, '.' );
		}
		return null;
	}

	/**
	 * Validate a price.
	 *
	 * @param string $value             Raw value.
	 * @param string $decimal_separator Separator.
	 * @return array{ok:bool,value?:float,error?:string}
	 */
	public function price( string $value, string $decimal_separator = '.' ): array {
		$n = self::parse_decimal( $value, $decimal_separator );
		if ( null === $n ) {
			return array(
				'ok'    => false,
				/* translators: %s: raw value. */
				'error' => sprintf( __( 'Invalid price value "%s".', 'micro-sync-pro' ), self::clip( $value ) ),
			);
		}
		$f = (float) $n;
		if ( ! is_finite( $f ) || $f < 0 ) {
			return array(
				'ok'    => false,
				/* translators: %s: raw value. */
				'error' => sprintf( __( 'Price cannot be negative ("%s").', 'micro-sync-pro' ), self::clip( $value ) ),
			);
		}
		if ( $f > self::MAX_PRICE ) {
			return array(
				'ok'    => false,
				/* translators: %s: raw value. */
				'error' => sprintf( __( 'Price is unrealistically large ("%s").', 'micro-sync-pro' ), self::clip( $value ) ),
			);
		}
		if ( 0.0 === $f && ! $this->settings->get( 'allow_zero_price' ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'Price is zero. Zero prices are rejected (see Settings).', 'micro-sync-pro' ),
			);
		}
		return array(
			'ok'    => true,
			'value' => $f,
		);
	}

	/**
	 * Validate a stock quantity.
	 *
	 * @param string $value Raw value.
	 * @return array{ok:bool,value?:int,error?:string}
	 */
	public function stock( string $value ): array {
		$n = self::parse_decimal( $value, '.' );
		if ( null === $n || ! preg_match( '/^-?\d+(\.0+)?$/', $n ) ) {
			return array(
				'ok'    => false,
				/* translators: %s: raw value. */
				'error' => sprintf( __( 'Invalid stock quantity "%s" (a whole number is required).', 'micro-sync-pro' ), self::clip( $value ) ),
			);
		}
		$i = (int) $n;
		if ( abs( $i ) > self::MAX_STOCK ) {
			return array(
				'ok'    => false,
				/* translators: %s: raw value. */
				'error' => sprintf( __( 'Stock quantity is out of range ("%s").', 'micro-sync-pro' ), self::clip( $value ) ),
			);
		}
		if ( $i < 0 && ! $this->settings->get( 'allow_negative_stock' ) ) {
			return array(
				'ok'    => false,
				/* translators: %s: raw value. */
				'error' => sprintf( __( 'Negative stock quantity "%s" is not allowed (see Settings).', 'micro-sync-pro' ), self::clip( $value ) ),
			);
		}
		return array(
			'ok'    => true,
			'value' => $i,
		);
	}

	/**
	 * Validate a stock status, accepting common aliases (English and Persian).
	 *
	 * @param string $value Raw value.
	 * @return array{ok:bool,value?:string,error?:string}
	 */
	public function stock_status( string $value ): array {
		$v       = trim( self::ascii_digits( $value ) );
		$key     = function_exists( 'mb_strtolower' ) ? mb_strtolower( $v ) : strtolower( $v );
		$key     = str_replace( array( '-', '_', ' ' ), '', $key );
		$aliases = array(
			'instock'     => 'instock',
			'1'           => 'instock',
			'yes'         => 'instock',
			'true'        => 'instock',
			'available'   => 'instock',
			'موجود'       => 'instock',
			'outofstock'  => 'outofstock',
			'0'           => 'outofstock',
			'no'          => 'outofstock',
			'false'       => 'outofstock',
			'unavailable' => 'outofstock',
			'ناموجود'     => 'outofstock',
			'onbackorder' => 'onbackorder',
			'backorder'   => 'onbackorder',
			'preorder'    => 'onbackorder',
			'پیشسفارش'    => 'onbackorder',
		);
		$key = str_replace( "\u{200C}", '', $key );
		if ( isset( $aliases[ $key ] ) ) {
			$status = $aliases[ $key ];
			if ( function_exists( 'wc_get_product_stock_status_options' ) && ! array_key_exists( $status, wc_get_product_stock_status_options() ) ) {
				return array(
					'ok'    => false,
					/* translators: %s: raw value. */
					'error' => sprintf( __( 'Unsupported stock status "%s".', 'micro-sync-pro' ), self::clip( $value ) ),
				);
			}
			return array(
				'ok'    => true,
				'value' => $status,
			);
		}
		return array(
			'ok'    => false,
			/* translators: %s: raw value. */
			'error' => sprintf( __( 'Unknown stock status "%s". Use instock, outofstock or onbackorder.', 'micro-sync-pro' ), self::clip( $value ) ),
		);
	}

	/**
	 * Shorten a value for messages.
	 *
	 * @param string $v Value.
	 */
	private static function clip( string $v ): string {
		$v = wp_strip_all_tags( $v );
		return function_exists( 'mb_strimwidth' ) ? mb_strimwidth( $v, 0, 40, '…' ) : substr( $v, 0, 40 );
	}
}
