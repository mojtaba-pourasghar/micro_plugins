<?php
/**
 * CSV parsing tests.
 *
 * @package MicroStudio\MicroSyncPro\Tests
 */

namespace MicroStudio\MicroSyncPro\Tests\Unit;

use MicroStudio\MicroSyncPro\Parser\CsvParser;
use MicroStudio\MicroSyncPro\Parser\ParseException;
use MicroStudio\MicroSyncPro\Tests\TestCase;

/**
 * @covers \MicroStudio\MicroSyncPro\Parser\CsvParser
 */
class CsvParserTest extends TestCase {

	private function rows( string $csv, array $opts = array() ): array {
		$p = new CsvParser();
		return iterator_to_array( $p->rows( $this->temp_file( $csv ), $opts ), true );
	}

	public function test_basic_rows_keyed_by_line_number_and_header(): void {
		$rows = $this->rows( "sku,price,stock\nA1,100,5\nB2,200,0\n" );
		$this->assertSame( array( 2, 3 ), array_keys( $rows ) );
		$this->assertSame( array( 'sku' => 'A1', 'price' => '100', 'stock' => '5' ), $rows[2] );
	}

	public function test_detects_semicolon_and_tab_delimiters(): void {
		$this->assertSame( 'B2', $this->rows( "sku;price\nA1;1\nB2;2\n" )[3]['sku'] );
		$this->assertSame( '2', $this->rows( "sku\tprice\nA1\t1\nB2\t2\n" )[3]['price'] );
		$this->assertSame( ';', CsvParser::detect_delimiter( "a;b;c\n1;2;3" ) );
	}

	public function test_explicit_delimiter_option(): void {
		$rows = $this->rows( "sku|price\nA|1\n", array( 'delimiter' => '|' ) );
		$this->assertSame( '1', $rows[2]['price'] );
	}

	public function test_strips_utf8_bom_and_handles_persian_text(): void {
		$rows = $this->rows( "\xEF\xBB\xBFsku,نام\nA1,کالای اول\n" );
		$this->assertArrayHasKey( 'sku', $rows[2] );
		$this->assertSame( 'کالای اول', $rows[2]['نام'] );
	}

	public function test_windows_1256_is_converted_to_utf8(): void {
		// "موجود" in Windows-1256.
		$rows = $this->rows( "sku,status\nA1,\xe3\xe6\xcc\xe6\xcf\n", array( 'encoding' => 'AUTO' ) );
		$this->assertSame( 'موجود', $rows[2]['status'] );
	}

	public function test_quoted_fields_with_delimiters_and_newlines(): void {
		$rows = $this->rows( "sku,name,price\n\"A,1\",\"Line1\nLine2\",\"1,250\"\n" );
		$row  = reset( $rows );
		$this->assertSame( 'A,1', $row['sku'] );
		$this->assertSame( "Line1\nLine2", $row['name'] );
		$this->assertSame( '1,250', $row['price'] );
	}

	public function test_blank_lines_and_empty_rows_are_skipped_and_values_trimmed(): void {
		$rows = $this->rows( "sku,price\n\n , \n  A1 , 10 \r\n" );
		$this->assertCount( 1, $rows );
		$this->assertSame( array( 'sku' => 'A1', 'price' => '10' ), reset( $rows ) );
	}

	public function test_short_rows_are_padded_and_duplicate_headers_made_unique(): void {
		$p = new CsvParser();
		$f = $this->temp_file( "sku,price,price,\nA1\n" );
		$this->assertSame( array( 'sku', 'price', 'price_2', 'column_4' ), $p->headers( $f ) );
		$rows = iterator_to_array( $p->rows( $f ) );
		$this->assertSame( '', $rows[2]['price'] );
	}

	public function test_empty_file_throws(): void {
		$this->expectException( ParseException::class );
		iterator_to_array( ( new CsvParser() )->rows( $this->temp_file( '' ) ) );
	}

	public function test_missing_file_throws(): void {
		$this->expectException( ParseException::class );
		( new CsvParser() )->headers( '/nonexistent/file.csv' );
	}

	public function test_streams_large_file_with_bounded_memory(): void {
		$path = $this->temp_file( '' );
		$fh   = fopen( $path, 'wb' );
		fwrite( $fh, "sku,price,stock\n" );
		for ( $i = 0; $i < 60000; $i++ ) {
			fwrite( $fh, "SKU-{$i},{$i}.50," . ( $i % 100 ) . "\n" );
		}
		fclose( $fh );
		$this->assertGreaterThan( 1000000, filesize( $path ) );
		$before = memory_get_usage();
		$count  = 0;
		$peak   = 0;
		foreach ( ( new CsvParser() )->rows( $path ) as $row ) {
			++$count;
			if ( 0 === $count % 10000 ) {
				$peak = max( $peak, memory_get_usage() - $before );
			}
		}
		$this->assertSame( 60000, $count );
		$this->assertLessThan( 1024 * 1024, $peak, 'Parser must not accumulate rows in memory.' );
	}
}
