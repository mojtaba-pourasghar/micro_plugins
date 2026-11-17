<?php
/**
 * Pricing rules.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Pricing;

use MicroStudio\MicroSyncPro\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Applies multiplier, profit margin, rounding and min/max limits. Category
 * rules override the global margin and limits; the first matching rule in the
 * configured order wins (rules may also cover child categories).
 */
class PricingEngine {

	/** @var Settings */
	private $settings;

	/** @var array<int,int[]> Cache of term => ancestor ids. */
	private $ancestors = array();

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Resolve the effective rule for a set of product category ids.
	 *
	 * @param int[] $category_ids Category ids.
	 * @return array{margin_percent:float,min_price:string,max_price:string,rule_term:int}
	 */
	public function rule_for( array $category_ids ): array {
		$global = array(
			'margin_percent' => (float) $this->settings->get( 'margin_percent' ),
			'min_price'      => (string) $this->settings->get( 'min_price' ),
			'max_price'      => (string) $this->settings->get( 'max_price' ),
			'rule_term'      => 0,
		);
		$rules = (array) $this->settings->get( 'category_rules' );
		if ( empty( $rules ) || empty( $category_ids ) ) {
			return $global;
		}
		$direct = array_map( 'intval', $category_ids );
		foreach ( $rules as $rule ) {
			$term = (int) $rule['term_id'];
			$hit  = in_array( $term, $direct, true );
			if ( ! $hit && ! empty( $rule['include_children'] ) ) {
				foreach ( $direct as $cat ) {
					if ( in_array( $term, $this->ancestors_of( $cat ), true ) ) {
						$hit = true;
						break;
					}
				}
			}
			if ( $hit ) {
				return array(
					'margin_percent' => '' === $rule['margin_percent'] ? $global['margin_percent'] : (float) $rule['margin_percent'],
					'min_price'      => '' !== $rule['min_price'] ? (string) $rule['min_price'] : $global['min_price'],
					'max_price'      => '' !== $rule['max_price'] ? (string) $rule['max_price'] : $global['max_price'],
					'rule_term'      => $term,
				);
			}
		}
		return $global;
	}

	/**
	 * Compute the final price.
	 *
	 * @param float               $base       Supplier price.
	 * @param array<string,mixed> $rule       Rule from rule_for().
	 * @param float               $multiplier Source multiplier (e.g. currency conversion).
	 * @return array{ok:bool,price:float,note:string}
	 */
	public function calculate( float $base, array $rule, float $multiplier = 1.0 ): array {
		$price = $base * ( $multiplier > 0 ? $multiplier : 1.0 );
		$price = $price * ( 1 + ( (float) $rule['margin_percent'] ) / 100 );
		$price = $this->round( $price );
		$note  = '';

		$min = '' !== $rule['min_price'] ? (float) $rule['min_price'] : null;
		$max = '' !== $rule['max_price'] ? (float) $rule['max_price'] : null;
		if ( ( null !== $min && $price < $min ) || ( null !== $max && $price > $max ) ) {
			if ( 'skip' === $this->settings->get( 'out_of_range' ) ) {
				return array(
					'ok'    => false,
					'price' => $price,
					'note'  => sprintf(
						/* translators: 1: price, 2: min, 3: max. */
						__( 'Calculated price %1$s is outside the allowed range (%2$s – %3$s); field skipped.', 'micro-sync-pro' ),
						self::fmt( $price ),
						null === $min ? '-' : self::fmt( $min ),
						null === $max ? '-' : self::fmt( $max )
					),
				);
			}
			$clamped = null !== $min && $price < $min ? $min : (float) $max;
			$note    = sprintf(
				/* translators: 1: calculated price, 2: limited price. */
				__( 'Calculated price %1$s was limited to %2$s.', 'micro-sync-pro' ),
				self::fmt( $price ),
				self::fmt( $clamped )
			);
			$price = $clamped;
		}
		return array(
			'ok'    => true,
			'price' => $price,
			'note'  => $note,
		);
	}

	/**
	 * Apply configured rounding.
	 *
	 * @param float $price Price.
	 */
	public function round( float $price ): float {
		$step = (int) $this->settings->get( 'rounding_step' );
		$dec  = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		if ( $step <= 0 ) {
			return round( $price, $dec );
		}
		$ratio = $price / $step;
		// Guard against float noise (e.g. 1499.9999999 / 100).
		$ratio = round( $ratio, 6 );
		switch ( $this->settings->get( 'rounding_mode' ) ) {
			case 'up':
				$ratio = ceil( $ratio );
				break;
			case 'down':
				$ratio = floor( $ratio );
				break;
			default:
				$ratio = round( $ratio );
		}
		return (float) ( $ratio * $step );
	}

	/**
	 * Format a number for messages.
	 *
	 * @param float $n Number.
	 */
	private static function fmt( float $n ): string {
		return function_exists( 'wc_format_decimal' ) ? (string) wc_format_decimal( $n, '', true ) : (string) $n;
	}

	/**
	 * Ancestors of a term (cached).
	 *
	 * @param int $term_id Term.
	 * @return int[]
	 */
	private function ancestors_of( int $term_id ): array {
		if ( ! isset( $this->ancestors[ $term_id ] ) ) {
			$this->ancestors[ $term_id ] = array_map( 'intval', get_ancestors( $term_id, 'product_cat', 'taxonomy' ) );
		}
		return $this->ancestors[ $term_id ];
	}
}
