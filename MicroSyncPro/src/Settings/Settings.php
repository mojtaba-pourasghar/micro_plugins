<?php
/**
 * Plugin settings.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Typed access to the msp_settings option with strict sanitization.
 */
class Settings {

	const OPTION = 'msp_settings';

	/** Allowed rounding steps. */
	const ROUNDING_STEPS = array( 0, 1, 10, 100, 1000, 10000 );

	/**
	 * Runtime override (tests / run snapshots).
	 *
	 * @var array<string,mixed>|null
	 */
	private $override = null;

	/**
	 * Default values.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'batch_size'             => 50,
			'time_limit'             => 20,
			'max_upload_mb'          => 20,
			'max_remote_mb'          => 20,
			'http_timeout'           => 30,
			'stale_minutes'          => 15,
			'max_resume_attempts'    => 3,
			'log_retention_days'     => 30,
			'history_retention_days' => 90,
			'delete_data_on_uninstall' => false,
			'margin_percent'         => 0.0,
			'min_price'              => '',
			'max_price'              => '',
			'out_of_range'           => 'clamp',
			'rounding_step'          => 0,
			'rounding_mode'          => 'nearest',
			'apply_pricing_to_sale'  => true,
			'allow_zero_price'       => false,
			'allow_negative_stock'   => false,
			'category_rules'         => array(),
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public function all(): array {
		if ( null !== $this->override ) {
			return $this->override;
		}
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return self::sanitize( array_merge( self::defaults(), $stored ) );
	}

	/**
	 * Get one setting.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public function get( string $key ) {
		$all = $this->all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : null;
	}

	/**
	 * Use a fixed settings array for the current process (e.g. run snapshot).
	 *
	 * @param array<string,mixed>|null $settings Settings or null to clear.
	 */
	public function set_override( ?array $settings ): void {
		$this->override = null === $settings ? null : self::sanitize( array_merge( self::defaults(), $settings ) );
	}

	/**
	 * Persist settings.
	 *
	 * @param array<string,mixed> $input Raw input.
	 */
	public function save( array $input ): array {
		$clean = self::sanitize( array_merge( self::defaults(), $input ) );
		update_option( self::OPTION, $clean, false );
		return $clean;
	}

	/**
	 * Sanitize a settings array. Unknown keys are dropped.
	 *
	 * @param array<string,mixed> $in Input.
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $in ): array {
		$d   = self::defaults();
		$out = array();

		$out['batch_size']             = self::int_range( $in['batch_size'] ?? $d['batch_size'], 1, 500 );
		$out['time_limit']             = self::int_range( $in['time_limit'] ?? $d['time_limit'], 5, 120 );
		$out['max_upload_mb']          = self::int_range( $in['max_upload_mb'] ?? $d['max_upload_mb'], 1, 512 );
		$out['max_remote_mb']          = self::int_range( $in['max_remote_mb'] ?? $d['max_remote_mb'], 1, 512 );
		$out['http_timeout']           = self::int_range( $in['http_timeout'] ?? $d['http_timeout'], 5, 120 );
		$out['stale_minutes']          = self::int_range( $in['stale_minutes'] ?? $d['stale_minutes'], 5, 1440 );
		$out['max_resume_attempts']    = self::int_range( $in['max_resume_attempts'] ?? $d['max_resume_attempts'], 0, 10 );
		$out['log_retention_days']     = self::int_range( $in['log_retention_days'] ?? $d['log_retention_days'], 1, 3650 );
		$out['history_retention_days'] = self::int_range( $in['history_retention_days'] ?? $d['history_retention_days'], 1, 3650 );
		$out['delete_data_on_uninstall'] = self::bool( $in['delete_data_on_uninstall'] ?? false );
		$out['apply_pricing_to_sale']  = self::bool( $in['apply_pricing_to_sale'] ?? $d['apply_pricing_to_sale'] );
		$out['allow_zero_price']       = self::bool( $in['allow_zero_price'] ?? false );
		$out['allow_negative_stock']   = self::bool( $in['allow_negative_stock'] ?? false );

		$out['margin_percent'] = self::float_range( $in['margin_percent'] ?? 0, -99.0, 10000.0 );
		$out['min_price']      = self::optional_price( $in['min_price'] ?? '' );
		$out['max_price']      = self::optional_price( $in['max_price'] ?? '' );
		if ( '' !== $out['min_price'] && '' !== $out['max_price'] && (float) $out['min_price'] > (float) $out['max_price'] ) {
			$out['max_price'] = '';
		}

		$out['out_of_range']  = in_array( $in['out_of_range'] ?? '', array( 'clamp', 'skip' ), true ) ? $in['out_of_range'] : 'clamp';
		$step                 = (int) ( $in['rounding_step'] ?? 0 );
		$out['rounding_step'] = in_array( $step, self::ROUNDING_STEPS, true ) ? $step : 0;
		$out['rounding_mode'] = in_array( $in['rounding_mode'] ?? '', array( 'nearest', 'up', 'down' ), true ) ? $in['rounding_mode'] : 'nearest';

		$out['category_rules'] = self::sanitize_rules( $in['category_rules'] ?? array() );

		return $out;
	}

	/**
	 * Sanitize category pricing rules.
	 *
	 * @param mixed $rules Raw rules.
	 * @return array<int,array<string,mixed>>
	 */
	public static function sanitize_rules( $rules ): array {
		if ( ! is_array( $rules ) ) {
			return array();
		}
		$clean = array();
		$seen  = array();
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$term_id = absint( $rule['term_id'] ?? 0 );
			if ( ! $term_id || isset( $seen[ $term_id ] ) ) {
				continue;
			}
			$seen[ $term_id ] = true;
			$margin           = $rule['margin_percent'] ?? '';
			$clean[]          = array(
				'term_id'          => $term_id,
				'margin_percent'   => ( '' === $margin || null === $margin ) ? '' : self::float_range( $margin, -99.0, 10000.0 ),
				'min_price'        => self::optional_price( $rule['min_price'] ?? '' ),
				'max_price'        => self::optional_price( $rule['max_price'] ?? '' ),
				'include_children' => self::bool( $rule['include_children'] ?? true ),
			);
			if ( count( $clean ) >= 200 ) {
				break;
			}
		}
		return $clean;
	}

	/**
	 * Clamp an int.
	 *
	 * @param mixed $v   Value.
	 * @param int   $min Min.
	 * @param int   $max Max.
	 */
	private static function int_range( $v, int $min, int $max ): int {
		$v = is_numeric( $v ) ? (int) $v : $min;
		return max( $min, min( $max, $v ) );
	}

	/**
	 * Clamp a float.
	 *
	 * @param mixed $v   Value.
	 * @param float $min Min.
	 * @param float $max Max.
	 */
	private static function float_range( $v, float $min, float $max ): float {
		$v = is_numeric( $v ) ? (float) $v : 0.0;
		if ( ! is_finite( $v ) ) {
			$v = 0.0;
		}
		return max( $min, min( $max, $v ) );
	}

	/**
	 * Optional non-negative price string.
	 *
	 * @param mixed $v Value.
	 */
	private static function optional_price( $v ): string {
		if ( null === $v || '' === $v || ! is_scalar( $v ) ) {
			return '';
		}
		$v = trim( (string) $v );
		if ( ! preg_match( '/^\d{1,15}(\.\d{1,6})?$/', $v ) ) {
			return '';
		}
		return $v;
	}

	/**
	 * Truthy check that handles form values.
	 *
	 * @param mixed $v Value.
	 */
	private static function bool( $v ): bool {
		if ( is_bool( $v ) ) {
			return $v;
		}
		return in_array( strtolower( (string) $v ), array( '1', 'yes', 'true', 'on' ), true );
	}
}
