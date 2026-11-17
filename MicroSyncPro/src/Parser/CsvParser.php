<?php
/**
 * Streaming CSV parser.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Parser;

defined( 'ABSPATH' ) || exit;

/**
 * Reads CSV files line by line with fgetcsv(). Supports delimiter
 * auto-detection, UTF-8 BOM, and Windows-1256 (Persian/Arabic Excel exports).
 */
class CsvParser implements ParserInterface {

	const DELIMITERS = array( ',', ';', "\t", '|' );

	/**
	 * {@inheritDoc}
	 */
	public function headers( string $path, array $options = array() ): array {
		foreach ( $this->iterate( $path, $options, true ) as $headers ) {
			return $headers;
		}
		return array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function rows( string $path, array $options = array() ): \Generator {
		yield from $this->iterate( $path, $options, false );
	}

	/**
	 * Core iterator.
	 *
	 * @param string              $path         Path.
	 * @param array<string,mixed> $options      Options: delimiter, encoding.
	 * @param bool                $headers_only Yield headers then stop.
	 * @return \Generator
	 * @throws ParseException On failure.
	 */
	private function iterate( string $path, array $options, bool $headers_only ): \Generator {
		if ( ! is_readable( $path ) ) {
			throw new ParseException( __( 'The source file cannot be read.', 'micro-sync-pro' ) );
		}
		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			throw new ParseException( __( 'The source file cannot be opened.', 'micro-sync-pro' ) );
		}
		try {
			$sample = (string) fread( $fh, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$bom    = 0 === strpos( $sample, "\xEF\xBB\xBF" );
			rewind( $fh );
			if ( $bom ) {
				fseek( $fh, 3 );
			}
			$delimiter = isset( $options['delimiter'] ) ? (string) $options['delimiter'] : 'auto';
			if ( 'tab' === $delimiter ) {
				$delimiter = "\t";
			}
			if ( ! in_array( $delimiter, self::DELIMITERS, true ) ) {
				$delimiter = self::detect_delimiter( $bom ? substr( $sample, 3 ) : $sample );
			}
			$encoding = isset( $options['encoding'] ) ? strtoupper( (string) $options['encoding'] ) : 'UTF-8';
			if ( ! in_array( $encoding, array( 'UTF-8', 'WINDOWS-1256', 'AUTO' ), true ) ) {
				$encoding = 'UTF-8';
			}
			if ( 'AUTO' === $encoding ) {
				$encoding = ( $bom || self::is_utf8( $sample ) ) ? 'UTF-8' : 'WINDOWS-1256';
			}

			$headers = null;
			$line    = 0;
			while ( true ) {
				$cells = fgetcsv( $fh, 0, $delimiter, '"', '' );
				if ( false === $cells ) {
					break;
				}
				++$line;
				if ( array( null ) === $cells ) {
					continue; // Blank line.
				}
				if ( 'UTF-8' !== $encoding ) {
					$cells = array_map(
						static function ( $c ) use ( $encoding ) {
							return self::convert( (string) $c, $encoding );
						},
						$cells
					);
				}
				if ( null === $headers ) {
					$headers = HeaderNormalizer::normalize( $cells );
					if ( $headers_only ) {
						yield $headers;
						return;
					}
					continue;
				}
				$row   = array();
				$empty = true;
				foreach ( $headers as $i => $name ) {
					$value        = isset( $cells[ $i ] ) ? trim( (string) $cells[ $i ] ) : '';
					$row[ $name ] = $value;
					if ( '' !== $value ) {
						$empty = false;
					}
				}
				if ( $empty ) {
					continue;
				}
				yield $line => $row;
			}
			if ( null === $headers ) {
				throw new ParseException( __( 'The CSV file has no header row.', 'micro-sync-pro' ) );
			}
		} finally {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	/**
	 * Guess the delimiter from a sample of the first lines.
	 *
	 * @param string $sample Sample.
	 */
	public static function detect_delimiter( string $sample ): string {
		$lines = array_slice( preg_split( '/\r\n|\r|\n/', $sample ), 0, 5 );
		$best  = ',';
		$score = 0;
		foreach ( self::DELIMITERS as $d ) {
			$counts = array();
			foreach ( $lines as $l ) {
				if ( '' === trim( $l ) ) {
					continue;
				}
				$counts[] = count( str_getcsv( $l, $d, '"', '' ) );
			}
			if ( empty( $counts ) ) {
				continue;
			}
			$min = min( $counts );
			if ( $min > 1 && $min > $score ) {
				$score = $min;
				$best  = $d;
			}
		}
		return $best;
	}

	/**
	 * Whether a string is valid UTF-8 (tolerates a truncated trailing sequence).
	 *
	 * @param string $s String.
	 */
	public static function is_utf8( string $s ): bool {
		if ( preg_match( '//u', $s ) ) {
			return true;
		}
		// The sample may cut a multi-byte char at the end.
		return (bool) preg_match( '//u', substr( $s, 0, -3 ) );
	}

	/**
	 * Convert a cell to UTF-8.
	 *
	 * @param string $value    Value.
	 * @param string $encoding Encoding.
	 */
	private static function convert( string $value, string $encoding ): string {
		if ( function_exists( 'iconv' ) ) {
			$out = @iconv( $encoding, 'UTF-8//IGNORE', $value ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid bytes are dropped.
			if ( false !== $out ) {
				return $out;
			}
		}
		if ( function_exists( 'mb_convert_encoding' ) && in_array( strtolower( $encoding ), array_map( 'strtolower', mb_list_encodings() ), true ) ) {
			return (string) mb_convert_encoding( $value, 'UTF-8', $encoding );
		}
		return $value;
	}
}
