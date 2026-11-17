<?php
/**
 * JSON parsing tests.
 *
 * @package MicroStudio\MicroSyncPro\Tests
 */

namespace MicroStudio\MicroSyncPro\Tests\Unit;

use MicroStudio\MicroSyncPro\Parser\JsonParser;
use MicroStudio\MicroSyncPro\Parser\ParseException;
use MicroStudio\MicroSyncPro\Tests\TestCase;

/**
 * @covers \MicroStudio\MicroSyncPro\Parser\JsonParser
 */
class JsonParserTest extends TestCase {

	private function rows( string $json, string $path = '' ): array {
		return iterator_to_array( ( new JsonParser() )->rows( $this->temp_file( $json, '.json' ), array( 'records_path' => $path ) ) );
	}

	public function test_top_level_list(): void {
		$rows = $this->rows( '[{"sku":"A1","price":1500.5,"stock":3},{"sku":"B2","price":"20","stock":null}]' );
		$this->assertCount( 2, $rows );
		$this->assertSame( array( 'sku' => 'A1', 'price' => '1500.5', 'stock' => '3' ), $rows[1] );
		$this->assertSame( '', $rows[2]['stock'] );
	}

	public function test_records_path_and_nested_flattening(): void {
		$rows = $this->rows( '{"meta":{"page":1},"data":{"items":[{"sku":"A1","pricing":{"base":10,"sale":8},"inventory":{"qty":4,"in_stock":true},"tags":["x"]}]}}', 'data.items' );
		$row  = $rows[1];
		$this->assertSame( '10', $row['pricing.base'] );
		$this->assertSame( '8', $row['pricing.sale'] );
		$this->assertSame( '1', $row['inventory.in_stock'] );
		$this->assertArrayNotHasKey( 'tags', $row, 'Lists inside a record are ignored.' );
	}

	public function test_single_list_property_is_auto_detected(): void {
		$rows = $this->rows( '{"products":[{"sku":"Z"}],"count":1}' );
		$this->assertSame( 'Z', $rows[1]['sku'] );
	}

	public function test_big_integers_are_kept_as_strings(): void {
		$rows = $this->rows( '[{"sku":12345678901234567890123,"price":1}]' );
		$this->assertSame( '12345678901234567890123', $rows[1]['sku'] );
	}

	public function test_headers_are_union_of_keys(): void {
		$headers = ( new JsonParser() )->headers( $this->temp_file( '[{"sku":"A"},{"sku":"B","price":2}]', '.json' ) );
		$this->assertSame( array( 'sku', 'price' ), $headers );
	}

	public function test_non_object_records_are_ignored(): void {
		$rows = $this->rows( '[1,"x",{"sku":"A"}]' );
		$this->assertSame( array( 3 ), array_keys( $rows ) );
	}

	public function test_invalid_json_throws(): void {
		$this->expectException( ParseException::class );
		$this->rows( '{"sku": "A",' );
	}

	public function test_missing_records_path_throws(): void {
		$this->expectException( ParseException::class );
		$this->rows( '{"data":[]}', 'items' );
	}

	public function test_malicious_records_path_rejected(): void {
		$this->expectException( ParseException::class );
		$this->rows( '{"data":[]}', '../etc' );
	}

	public function test_scalar_document_throws(): void {
		$this->expectException( ParseException::class );
		$this->rows( '"just a string"' );
	}
}
