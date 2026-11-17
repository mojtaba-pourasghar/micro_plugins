<?php
/**
 * Source value object.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * A configured data source.
 */
class Source {

	/** @var int */
	public $id = 0;
	/** @var string */
	public $name = '';
	/** @var string */
	public $type = SourceTypes::CSV_UPLOAD;
	/** @var string */
	public $status = 'active';
	/** @var array<string,mixed> Config (secrets encrypted). */
	public $config = array();
	/** @var array<string,array{column:string,enabled:bool}> */
	public $mapping = array();
	/** @var string[] */
	public $columns = array();
	/** @var string */
	public $schedule = 'none';
	/** @var int */
	public $last_run_id = 0;
	/** @var string */
	public $created_at = '';
	/** @var string */
	public $updated_at = '';

	/**
	 * Hydrate from a DB row.
	 *
	 * @param object $row Row.
	 */
	public static function from_row( $row ): Source {
		$s              = new self();
		$s->id          = (int) $row->id;
		$s->name        = (string) $row->name;
		$s->type        = (string) $row->type;
		$s->status      = (string) $row->status;
		$s->config      = self::decode( $row->config );
		$s->mapping     = self::decode( $row->mapping );
		$s->columns     = array_values( array_filter( self::decode( $row->columns_detected ), 'is_string' ) );
		$s->schedule    = (string) $row->schedule;
		$s->last_run_id = (int) $row->last_run_id;
		$s->created_at  = (string) $row->created_at;
		$s->updated_at  = (string) $row->updated_at;
		return $s;
	}

	/**
	 * Decode JSON column.
	 *
	 * @param mixed $json JSON.
	 * @return array<mixed>
	 */
	private static function decode( $json ): array {
		if ( ! is_string( $json ) || '' === $json ) {
			return array();
		}
		$v = json_decode( $json, true );
		return is_array( $v ) ? $v : array();
	}

	/**
	 * File format.
	 */
	public function format(): string {
		return SourceTypes::format( $this->type );
	}

	/**
	 * Whether remote.
	 */
	public function is_remote(): bool {
		return SourceTypes::is_remote( $this->type );
	}

	/**
	 * Config value.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public function opt( string $key, $default = '' ) {
		return array_key_exists( $key, $this->config ) ? $this->config[ $key ] : $default;
	}

	/**
	 * Parser options.
	 *
	 * @return array<string,mixed>
	 */
	public function parser_options(): array {
		return array(
			'delimiter'    => (string) $this->opt( 'delimiter', 'auto' ),
			'encoding'     => (string) $this->opt( 'encoding', 'AUTO' ),
			'sheet'        => (int) $this->opt( 'sheet', 1 ),
			'records_path' => (string) $this->opt( 'records_path', '' ),
		);
	}

	/**
	 * Options relevant to record processing (stored into run snapshots).
	 *
	 * @return array<string,mixed>
	 */
	public function processing_options(): array {
		return array(
			'decimal_separator'       => '.' === $this->opt( 'decimal_separator', '.' ) ? '.' : ',',
			'price_multiplier'        => (float) $this->opt( 'price_multiplier', 1 ),
			'clear_sale_when_empty'   => (bool) $this->opt( 'clear_sale_when_empty', false ),
			'enable_stock_management' => (bool) $this->opt( 'enable_stock_management', false ),
		);
	}
}
