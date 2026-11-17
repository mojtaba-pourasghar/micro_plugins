<?php
/**
 * Pricing rule tests.
 *
 * @package MicroStudio\MicroSyncPro\Tests
 */

namespace MicroStudio\MicroSyncPro\Tests\Unit;

use MicroStudio\MicroSyncPro\Pricing\PricingEngine;
use MicroStudio\MicroSyncPro\Settings\Settings;
use MicroStudio\MicroSyncPro\Tests\TestCase;

/**
 * @covers \MicroStudio\MicroSyncPro\Pricing\PricingEngine
 * @covers \MicroStudio\MicroSyncPro\Settings\Settings
 */
class PricingTest extends TestCase {

	private function engine( array $settings ): PricingEngine {
		$s = new Settings();
		$s->set_override( $settings );
		return new PricingEngine( $s );
	}

	public function test_margin_and_multiplier(): void {
		$e = $this->engine( array( 'margin_percent' => 20 ) );
		$r = $e->calculate( 1000, $e->rule_for( array() ), 0.1 );
		$this->assertTrue( $r['ok'] );
		$this->assertEqualsWithDelta( 120.0, $r['price'], 0.0001 );
	}

	public function test_rounding_modes(): void {
		$this->assertSame( 12300.0, $this->engine( array( 'rounding_step' => 100, 'rounding_mode' => 'nearest' ) )->round( 12345 ) );
		$this->assertSame( 12400.0, $this->engine( array( 'rounding_step' => 100, 'rounding_mode' => 'up' ) )->round( 12301 ) );
		$this->assertSame( 12000.0, $this->engine( array( 'rounding_step' => 1000, 'rounding_mode' => 'down' ) )->round( 12999 ) );
		$this->assertSame( 1500.0, $this->engine( array( 'rounding_step' => 100, 'rounding_mode' => 'up' ) )->round( 1499.99999999 + 0.00000001 ) );
		$this->assertSame( 10.13, $this->engine( array() )->round( 10.126 ) );
	}

	public function test_min_max_clamp_and_skip(): void {
		$clamp = $this->engine( array( 'min_price' => '50', 'max_price' => '500' ) );
		$r     = $clamp->calculate( 10, $clamp->rule_for( array() ) );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 50.0, $r['price'] );
		$this->assertNotSame( '', $r['note'] );
		$this->assertSame( 500.0, $clamp->calculate( 9000, $clamp->rule_for( array() ) )['price'] );

		$skip = $this->engine( array( 'min_price' => '50', 'out_of_range' => 'skip' ) );
		$this->assertFalse( $skip->calculate( 10, $skip->rule_for( array() ) )['ok'] );
	}

	public function test_category_rules_first_match_and_children(): void {
		$parent = self::factory()->term->create( array( 'taxonomy' => 'product_cat', 'name' => 'Phones' ) );
		$child  = self::factory()->term->create( array( 'taxonomy' => 'product_cat', 'name' => 'Android', 'parent' => $parent ) );
		$other  = self::factory()->term->create( array( 'taxonomy' => 'product_cat', 'name' => 'Cases' ) );
		$e      = $this->engine(
			array(
				'margin_percent' => 10,
				'category_rules' => array(
					array( 'term_id' => $other, 'margin_percent' => 50, 'min_price' => '', 'max_price' => '', 'include_children' => true ),
					array( 'term_id' => $parent, 'margin_percent' => 5, 'min_price' => '', 'max_price' => '900', 'include_children' => true ),
				),
			)
		);
		$this->assertSame( 50.0, $e->rule_for( array( $other, $child ) )['margin_percent'], 'First rule wins.' );
		$r = $e->rule_for( array( $child ) );
		$this->assertSame( 5.0, $r['margin_percent'], 'Rule applies to child categories.' );
		$this->assertSame( '900', $r['max_price'] );
		$this->assertSame( 10.0, $e->rule_for( array( 999999 ) )['margin_percent'], 'Falls back to global.' );
	}

	public function test_settings_sanitization(): void {
		$clean = Settings::sanitize(
			array(
				'batch_size'     => 100000,
				'margin_percent' => 'abc',
				'min_price'      => '-5',
				'max_price'      => '1e9',
				'rounding_step'  => 7,
				'out_of_range'   => 'explode',
				'category_rules' => array( array( 'term_id' => 'x' ), 'junk', array( 'term_id' => 3, 'margin_percent' => '12.5' ), array( 'term_id' => 3 ) ),
				'evil_key'       => 'x',
			)
		);
		$this->assertSame( 500, $clean['batch_size'] );
		$this->assertSame( 0.0, $clean['margin_percent'] );
		$this->assertSame( '', $clean['min_price'] );
		$this->assertSame( '', $clean['max_price'] );
		$this->assertSame( 0, $clean['rounding_step'] );
		$this->assertSame( 'clamp', $clean['out_of_range'] );
		$this->assertCount( 1, $clean['category_rules'] );
		$this->assertSame( 12.5, $clean['category_rules'][0]['margin_percent'] );
		$this->assertArrayNotHasKey( 'evil_key', $clean );
	}
}
