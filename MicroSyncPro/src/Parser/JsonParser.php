<?php
/**
 * JSON parser.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Parser;

defined( 'ABSPATH' ) || exit;

/**
 * Parses a JSON document (size-limited before it reaches this class) and
 * yields flat records. Records are located with an optional dot path such as
 * "data.items"; nested objects are flattened to dot keys ("stock.qty").
 */
class JsonParser implements ParserInterface {

	const MAX_DEPTH = 64;

	/**
	 * {@inheritDoc}
	 */
	public function headers( string $path, array $options = array() ): array {
		$keys  = array();
		$count = 0;
		foreach ( $this->rows( $path, $options ) as $row ) {
			foreach ( array_keys( $row ) as $k ) {
				$keys[ $k ] = true;
			}
			if ( ++$count >= 200 ) {
				break;
			}
		}
		return array_slice( array_keys( $keys ), 0, HeaderNormalizer::MAX_COLUMNS );
	}

	/**
	 * {@inheritDoc}
	 */
	public function rows( string $path, array $options = array() ): \Generator {
		if ( ! is_readable( $path ) ) {
			throw new ParseException( __( 'The source file cannot be read.', 'micro-sync-pro' ) );
		}
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $raw ) {
			throw new ParseException( __( 'The source file cannot be read.', 'micro-sync-pro' ) );
		}
		$records = $this->decode_records( $raw, (string) ( $options['records_path'] ?? '' ) );
		unset( $raw );
		$n = 0;
		foreach ( $records as $record ) {
			++$n;
			if ( ! is_array( $record ) ) {
				continue;
			}
			$flat = array();
			self::flatten( $record, '', $flat, 0 );
			if ( ! empty( $flat ) ) {
				yield $n => $flat;
			}
		}
	}

	/**
	 * Decode JSON and locate the records list.
	 *
	 * @param string $raw  JSON.
	 * @param string $path Dot path.
	 * @return array<int,mixed>
	 * @throws ParseException On invalid JSON or path.
	 */
	public function decode_records( string $raw, string $path ): array {
		$raw = preg_replace( '/^\xEF\xBB\xBF/', '', $raw );
		$data = json_decode( (string) $raw, true, self::MAX_DEPTH, JSON_BIGINT_AS_STRING );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new ParseException(
				sprintf(
					/* translators: %s: JSON error message. */
					__( 'Invalid JSON: %s', 'micro-sync-pro' ),
					json_last_error_msg()
				)
			);
		}
		$path = trim( $path );
		if ( '' !== $path ) {
			if ( ! preg_match( '/^[A-Za-z0-9_\-]+(\.[A-Za-z0-9_\-]+)*$/', $path ) ) {
				throw new ParseException( __( 'The records path is not valid.', 'micro-sync-pro' ) );
			}
			foreach ( explode( '.', $path ) as $segment ) {
				if ( ! is_array( $data ) || ! array_key_exists( $segment, $data ) ) {
					throw new ParseException( __( 'The records path was not found in the JSON document.', 'micro-sync-pro' ) );
				}
				$data = $data[ $segment ];
			}
		}
		if ( ! is_array( $data ) ) {
			throw new ParseException( __( 'The JSON records must be an array of objects.', 'micro-sync-pro' ) );
		}
		// A single top-level object that is itself a record list keyed by SKU is
		// not supported; an object with one list property is auto-detected.
		if ( ! self::is_list( $data ) ) {
			$lists = array_filter( $data, static function ( $v ) { return is_array( $v ) && self::is_list( $v ); } );
			if ( '' === $path && 1 === count( $lists ) ) {
				return array_values( reset( $lists ) );
			}
			throw new ParseException( __( 'The JSON records must be an array of objects. Set the records path (for example "data.items").', 'micro-sync-pro' ) );
		}
		return $data;
	}

	/**
	 * Flatten nested arrays into dot keys.
	 *
	 * @param array<string|int,mixed> $in     Input.
	 * @param string                  $prefix Prefix.
	 * @param array<string,string>    $out    Output.
	 * @param int                     $depth  Depth.
	 */
	private static function flatten( array $in, string $prefix, array &$out, int $depth ): void {
		foreach ( $in as $k => $v ) {
			$key = HeaderNormalizer::clean( '' === $prefix ? (string) $k : $prefix . '.' . $k );
			if ( '' === $key ) {
				continue;
			}
			if ( is_array( $v ) ) {
				if ( $depth < 3 && ! self::is_list( $v ) ) {
					self::flatten( $v, $key, $out, $depth + 1 );
				}
				continue;
			}
			if ( count( $out ) >= HeaderNormalizer::MAX_COLUMNS ) {
				return;
			}
			if ( is_bool( $v ) ) {
				$v = $v ? '1' : '0';
			} elseif ( null === $v ) {
				$v = '';
			} elseif ( is_float( $v ) ) {
				$v = XlsxParser::normalize_number( (string) $v );
			}
			$out[ $key ] = trim( (string) $v );
		}
	}

	/**
	 * Whether an array is a list.
	 *
	 * @param array<mixed> $a Array.
	 */
	private static function is_list( array $a ): bool {
		return array() === $a || array_keys( $a ) === range( 0, count( $a ) - 1 );
	}
}
