<?php
/**
 * Price / stock validation tests.
 *
 * @package MicroStudio\MicroSyncPro\Tests
 */

namespace MicroStudio\MicroSyncPro\Tests\Unit;

use MicroStudio\MicroSyncPro\Settings\Settings;
use MicroStudio\MicroSyncPro\Tests\TestCase;
use MicroStudio\MicroSyncPro\Validation\RecordValidator;

/**
 * @covers \MicroStudio\MicroSyncPro\Validation\RecordValidator
 */
class ValidationTest extends TestCase {

	private function validator( array $settings = array() ): RecordValidator {
		$s = new Settings();
		$s->set_override( $settings );
		return new RecordValidator( $s );
	}

	/**
	 * @dataProvider valid_prices
	 */
	public function test_valid_prices( string $raw, string $sep, float $expected ): void {
		$r = $this->validator()->price( $raw, $sep );
		$this->assertTrue( $r['ok'], $raw . ': ' . ( $r['error'] ?? '' ) );
		$this->assertEqualsWithDelta( $expected, $r['value'], 0.000001 );
	}

	public function valid_prices(): array {
		return array(
			'integer'                 => array( '1500', '.', 1500.0 ),
			'decimal'                 => array( '12.75', '.', 12.75 ),
			'thousands'               => array( '1,250,000', '.', 1250000.0 ),
			'thousands + decimal'     => array( '1,250,000.50', '.', 1250000.5 ),
			'comma decimal'           => array( '1.250.000,50', ',', 1250000.5 ),
			'persian digits'          => array( '۱۲۵۰۰۰۰', '.', 1250000.0 ),
			'persian separators'      => array( '۱٬۲۵۰٬۰۰۰٫۵', '.', 1250000.5 ),
			'arabic-indic digits'     => array( '٣٤٥', '.', 345.0 ),
			'currency suffix'         => array( '125,000 تومان', '.', 125000.0 ),
			'currency prefix'         => array( '$19.99', '.', 19.99 ),
			'spaces'                  => array( ' 1 500 ', '.', 1500.0 ),
			'trailing dot'            => array( '10.', '.', 10.0 ),
		);
	}

	/**
	 * @dataProvider invalid_prices
	 */
	public function test_invalid_prices( string $raw ): void {
		$r = $this->validator()->price( $raw, '.' );
		$this->assertFalse( $r['ok'], $raw );
		$this->assertNotEmpty( $r['error'] );
	}

	public function invalid_prices(): array {
		return array(
			'text'              => array( 'abc' ),
			'negative'          => array( '-5' ),
			'malformed comma'   => array( '1,2' ),
			'two dots'          => array( '1.2.3' ),
			'scientific'        => array( '1e5' ),
			'zero rejected'     => array( '0' ),
			'huge'              => array( '99999999999999' ),
			'embedded letters'  => array( '12abc34' ),
			'hex'               => array( '0x1A' ),
			'only currency'     => array( 'ریال' ),
		);
	}

	public function test_zero_price_allowed_when_configured(): void {
		$r = $this->validator( array( 'allow_zero_price' => true ) )->price( '0', '.' );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 0.0, $r['value'] );
	}

	public function test_stock_validation(): void {
		$v = $this->validator();
		$this->assertSame( 12, $v->stock( '12' )['value'] );
		$this->assertSame( 12, $v->stock( '12.0' )['value'] );
		$this->assertSame( 7, $v->stock( '۷' )['value'] );
		$this->assertSame( 1500, $v->stock( '1,500' )['value'] );
		$this->assertFalse( $v->stock( '1.5' )['ok'], 'Fractional stock is rejected.' );
		$this->assertFalse( $v->stock( 'many' )['ok'] );
		$this->assertFalse( $v->stock( '-3' )['ok'], 'Negative stock rejected by default.' );
		$this->assertFalse( $v->stock( '5000000000' )['ok'] );
		$this->assertSame( -3, $this->validator( array( 'allow_negative_stock' => true ) )->stock( '-3' )['value'] );
	}

	public function test_stock_status_aliases(): void {
		$v     = $this->validator();
		$cases = array(
			'instock'      => 'instock',
			'In Stock'     => 'instock',
			'1'            => 'instock',
			'yes'          => 'instock',
			'موجود'        => 'instock',
			'outofstock'   => 'outofstock',
			'out-of-stock' => 'outofstock',
			'0'            => 'outofstock',
			'ناموجود'      => 'outofstock',
			'onbackorder'  => 'onbackorder',
			'backorder'    => 'onbackorder',
			'پیش‌سفارش'    => 'onbackorder',
		);
		foreach ( $cases as $raw => $expected ) {
			$r = $v->stock_status( (string) $raw );
			$this->assertTrue( $r['ok'], (string) $raw );
			$this->assertSame( $expected, $r['value'], (string) $raw );
		}
		$this->assertFalse( $v->stock_status( 'maybe' )['ok'] );
	}
}
