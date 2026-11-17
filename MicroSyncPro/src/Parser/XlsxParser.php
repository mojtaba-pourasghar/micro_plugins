<?php
/**
 * Streaming XLSX parser (no external library).
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Parser;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the first (or a selected) worksheet of an XLSX workbook with
 * ZipArchive + XMLReader, row by row. Shared strings are loaded once (they
 * are needed for random access); worksheet rows are streamed.
 */
class XlsxParser implements ParserInterface {

	/** Maximum number of shared strings accepted (memory guard). */
	const MAX_SHARED_STRINGS = 2000000;

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
	 * Iterate rows.
	 *
	 * @param string              $path         Path.
	 * @param array<string,mixed> $options      Options: sheet (1-based index).
	 * @param bool                $headers_only Stop after headers.
	 * @return \Generator
	 * @throws ParseException On failure.
	 */
	private function iterate( string $path, array $options, bool $headers_only ): \Generator {
		if ( ! class_exists( 'ZipArchive' ) || ! class_exists( 'XMLReader' ) ) {
			throw new ParseException( __( 'XLSX support requires the PHP zip and xmlreader extensions.', 'micro-sync-pro' ) );
		}
		if ( ! is_readable( $path ) ) {
			throw new ParseException( __( 'The source file cannot be read.', 'micro-sync-pro' ) );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::RDONLY ) ) {
			throw new ParseException( __( 'The file is not a valid XLSX workbook.', 'micro-sync-pro' ) );
		}
		$sheet_index = max( 1, (int) ( $options['sheet'] ?? 1 ) );
		$sheet_path  = $this->sheet_path( $zip, $sheet_index );
		$strings     = $this->shared_strings( $zip );
		$zip->close();

		if ( '' === $sheet_path ) {
			throw new ParseException( __( 'The selected worksheet does not exist.', 'micro-sync-pro' ) );
		}

		$reader = new \XMLReader();
		$uri    = 'zip://' . $path . '#' . $sheet_path;
		if ( ! @$reader->open( $uri, null, LIBXML_NONET | LIBXML_COMPACT ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			throw new ParseException( __( 'The worksheet cannot be read.', 'micro-sync-pro' ) );
		}

		$headers = null;
		try {
			while ( $reader->read() ) {
				if ( \XMLReader::ELEMENT !== $reader->nodeType || 'row' !== $reader->localName ) {
					continue;
				}
				$row_num = (int) $reader->getAttribute( 'r' );
				$xml     = $reader->readOuterXml();
				$cells   = $this->parse_row( $xml, $strings );
				if ( empty( $cells ) ) {
					continue;
				}
				if ( null === $headers ) {
					$max  = max( array_keys( $cells ) );
					$list = array();
					for ( $i = 0; $i <= $max && $i < HeaderNormalizer::MAX_COLUMNS; $i++ ) {
						$list[] = $cells[ $i ] ?? '';
					}
					$headers = HeaderNormalizer::normalize( $list );
					if ( $headers_only ) {
						yield $headers;
						return;
					}
					continue;
				}
				$row   = array();
				$empty = true;
				foreach ( $headers as $i => $name ) {
					$v            = isset( $cells[ $i ] ) ? trim( $cells[ $i ] ) : '';
					$row[ $name ] = $v;
					if ( '' !== $v ) {
						$empty = false;
					}
				}
				if ( ! $empty ) {
					yield ( $row_num > 0 ? $row_num : 0 ) => $row;
				}
			}
		} finally {
			$reader->close();
		}
		if ( null === $headers ) {
			throw new ParseException( __( 'The worksheet has no header row.', 'micro-sync-pro' ) );
		}
	}

	/**
	 * Parse one <row> element into column-index => value.
	 *
	 * @param string   $xml     Row XML.
	 * @param string[] $strings Shared strings.
	 * @return array<int,string>
	 */
	private function parse_row( string $xml, array $strings ): array {
		$prev = libxml_use_internal_errors( true );
		$node = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET );
		libxml_use_internal_errors( $prev );
		if ( false === $node ) {
			return array();
		}
		$cells = array();
		$next  = 0;
		foreach ( $node->children() as $c ) {
			if ( 'c' !== $c->getName() ) {
				continue;
			}
			$ref = (string) $c['r'];
			$idx = '' !== $ref ? self::column_index( $ref ) : $next;
			$next = $idx + 1;
			$type = (string) $c['t'];
			$val  = '';
			if ( 's' === $type ) {
				$si  = (int) $c->v;
				$val = $strings[ $si ] ?? '';
			} elseif ( 'inlineStr' === $type ) {
				$val = self::rich_text( $c->is );
			} elseif ( 'b' === $type ) {
				$val = ( '1' === (string) $c->v ) ? '1' : '0';
			} else {
				$val = (string) $c->v;
				if ( 'n' === $type || '' === $type ) {
					$val = self::normalize_number( $val );
				}
			}
			$cells[ $idx ] = $val;
		}
		return $cells;
	}

	/**
	 * Clean floating-point artifacts such as 0.30000000000000004.
	 *
	 * @param string $v Value.
	 */
	public static function normalize_number( string $v ): string {
		if ( ! is_numeric( $v ) || false === strpbrk( $v, '.eE' ) ) {
			return $v;
		}
		$f = (float) $v;
		if ( ! is_finite( $f ) ) {
			return $v;
		}
		$s = rtrim( rtrim( number_format( $f, 10, '.', '' ), '0' ), '.' );
		return '' === $s || '-0' === $s ? '0' : $s;
	}

	/**
	 * Column letters (from a cell reference like "AB12") to zero-based index.
	 *
	 * @param string $ref Cell reference.
	 */
	public static function column_index( string $ref ): int {
		$letters = strtoupper( (string) preg_replace( '/[^A-Za-z]/', '', $ref ) );
		$n       = 0;
		$len     = strlen( $letters );
		for ( $i = 0; $i < $len; $i++ ) {
			$n = $n * 26 + ( ord( $letters[ $i ] ) - 64 );
		}
		return max( 0, $n - 1 );
	}

	/**
	 * Concatenate rich-text runs.
	 *
	 * @param \SimpleXMLElement|null $node Node (<si> or <is>).
	 */
	private static function rich_text( $node ): string {
		if ( null === $node ) {
			return '';
		}
		if ( isset( $node->t ) ) {
			return (string) $node->t;
		}
		$out = '';
		foreach ( $node->r as $run ) {
			$out .= (string) $run->t;
		}
		return $out;
	}

	/**
	 * Resolve the worksheet part path for a 1-based sheet index.
	 *
	 * @param \ZipArchive $zip   Archive.
	 * @param int         $index Index.
	 */
	private function sheet_path( \ZipArchive $zip, int $index ): string {
		$workbook = $zip->getFromName( 'xl/workbook.xml' );
		$rels     = $zip->getFromName( 'xl/_rels/workbook.xml.rels' );
		if ( false === $workbook ) {
			return '';
		}
		$prev = libxml_use_internal_errors( true );
		$wb   = simplexml_load_string( $workbook, 'SimpleXMLElement', LIBXML_NONET );
		$rl   = false !== $rels ? simplexml_load_string( $rels, 'SimpleXMLElement', LIBXML_NONET ) : false;
		libxml_use_internal_errors( $prev );
		if ( false === $wb ) {
			return '';
		}
		$sheets = array();
		foreach ( $wb->sheets->children() as $sheet ) {
			$attrs    = $sheet->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' );
			$sheets[] = isset( $attrs['id'] ) ? (string) $attrs['id'] : '';
		}
		$rid = $sheets[ $index - 1 ] ?? '';
		if ( '' !== $rid && false !== $rl ) {
			foreach ( $rl->children() as $rel ) {
				if ( (string) $rel['Id'] === $rid ) {
					$target = ltrim( (string) $rel['Target'], '/' );
					$target = 0 === strpos( $target, 'xl/' ) ? $target : 'xl/' . $target;
					if ( false === strpos( $target, '..' ) && false !== $zip->locateName( $target ) ) {
						return $target;
					}
				}
			}
		}
		$fallback = 'xl/worksheets/sheet' . $index . '.xml';
		return false !== $zip->locateName( $fallback ) ? $fallback : '';
	}

	/**
	 * Load shared strings using a streaming reader.
	 *
	 * @param \ZipArchive $zip Archive.
	 * @return string[]
	 * @throws ParseException When too many strings.
	 */
	private function shared_strings( \ZipArchive $zip ): array {
		if ( false === $zip->locateName( 'xl/sharedStrings.xml' ) ) {
			return array();
		}
		$stream = $zip->getStream( 'xl/sharedStrings.xml' );
		if ( ! $stream ) {
			return array();
		}
		$tmp = wp_tempnam( 'msp-ss' );
		$out = fopen( $tmp, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		stream_copy_to_stream( $stream, $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$strings = array();
		$reader  = new \XMLReader();
		try {
			if ( ! @$reader->open( $tmp, null, LIBXML_NONET | LIBXML_COMPACT ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return array();
			}
			while ( $reader->read() ) {
				if ( \XMLReader::ELEMENT === $reader->nodeType && 'si' === $reader->localName ) {
					$prev = libxml_use_internal_errors( true );
					$si   = simplexml_load_string( $reader->readOuterXml(), 'SimpleXMLElement', LIBXML_NONET );
					libxml_use_internal_errors( $prev );
					$strings[] = false === $si ? '' : self::rich_text( $si );
					if ( count( $strings ) > self::MAX_SHARED_STRINGS ) {
						throw new ParseException( __( 'The workbook contains too many distinct text values.', 'micro-sync-pro' ) );
					}
				}
			}
			$reader->close();
		} finally {
			wp_delete_file( $tmp );
		}
		return $strings;
	}
}
