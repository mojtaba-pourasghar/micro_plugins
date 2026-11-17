<?php
/**
 * Product update tests (simple + variations) through the CRUD API.
 *
 * @package MicroStudio\MicroSyncPro\Tests
 */

namespace MicroStudio\MicroSyncPro\Tests\Integration;

use MicroStudio\MicroSyncPro\Sync\ProductUpdater;
use MicroStudio\MicroSyncPro\Tests\TestCase;

/**
 * @covers \MicroStudio\MicroSyncPro\Sync\ProductUpdater
 */
class ProductUpdaterTest extends TestCase {

	private function process( \WC_Product $p, array $data, array $opts = array(), bool $apply = true ): array {
		return $this->plugin()->updater()->process( $p, $data, $opts + array( 'decimal_separator' => '.', 'price_multiplier' => 1.0 ), $apply );
	}

	public function test_simple_product_price_and_stock_update(): void {
		$p = $this->simple( 'S1', '100', 5 );
		$r = $this->process( $p, array( 'sku' => 'S1', 'regular_price' => '150', 'stock_quantity' => '0' ) );
		$this->assertSame( 'updated', $r['status'] );
		$this->assertSame( '100', $r['changes']['regular_price']['before'] );
		$this->assertSame( '150.00', $r['changes']['regular_price']['after'] );
		$this->assertSame( 5, $r['changes']['stock_quantity']['before'] );
		$fresh = $this->product( $p->get_id() );
		$this->assertSame( '150.00', $fresh->get_regular_price() );
		$this->assertSame( 0, $fresh->get_stock_quantity() );
		$this->assertSame( 'outofstock', $fresh->get_stock_status(), 'WooCommerce stock rules are respected.' );
		$this->assertSame( 'outofstock', $r['changes']['stock_status_after_save'] );
	}

	public function test_only_selected_fields_are_touched(): void {
		$p = $this->simple( 'S2', '100', 5 );
		$p->set_sale_price( '90' );
		$p->save();
		$r = $this->process( $p, array( 'sku' => 'S2', 'stock_quantity' => '9' ) );
		$this->assertSame( array( 'stock_quantity' ), array_keys( array_diff_key( $r['changes'], array( 'stock_status_after_save' => 1 ) ) ) );
		$fresh = $this->product( $p->get_id() );
		$this->assertSame( '100', $fresh->get_regular_price() );
		$this->assertSame( '90', $fresh->get_sale_price() );
	}

	public function test_unchanged_record(): void {
		$p = $this->simple( 'S3', '100', 5 );
		$r = $this->process( $p, array( 'sku' => 'S3', 'regular_price' => '100.00', 'stock_quantity' => '5' ) );
		$this->assertSame( 'unchanged', $r['status'] );
	}

	public function test_invalid_values_fail_the_whole_record_without_saving(): void {
		$p = $this->simple( 'S4', '100', 5 );
		$r = $this->process( $p, array( 'sku' => 'S4', 'regular_price' => '200', 'stock_quantity' => 'abc' ) );
		$this->assertSame( 'failed', $r['status'] );
		$this->assertSame( 'invalid_value', $r['code'] );
		$this->assertSame( '100', $this->product( $p->get_id() )->get_regular_price() );
	}

	public function test_dry_run_does_not_save(): void {
		$p = $this->simple( 'S5', '100', 5 );
		$r = $this->process( $p, array( 'sku' => 'S5', 'regular_price' => '300' ), array(), false );
		$this->assertSame( 'would_update', $r['status'] );
		$this->assertSame( '300.00', $r['changes']['regular_price']['after'] );
		$this->assertSame( '100', $this->product( $p->get_id() )->get_regular_price() );
	}

	public function test_sale_price_rules(): void {
		$p = $this->simple( 'S6', '100', 5 );
		$r = $this->process( $p, array( 'sku' => 'S6', 'regular_price' => '100', 'sale_price' => '120' ) );
		$this->assertSame( 'unchanged', $r['status'] );
		$this->assertStringContainsString( 'not lower', $r['message'] );

		$r = $this->process( $p, array( 'sku' => 'S6', 'sale_price' => '80' ) );
		$this->assertSame( 'updated', $r['status'] );
		$this->assertSame( '80.00', $this->product( $p->get_id() )->get_sale_price() );

		$r = $this->process( $this->product( $p->get_id() ), array( 'sku' => 'S6', 'sale_price' => '' ) );
		$this->assertSame( 'unchanged', $r['status'], 'Empty sale price is ignored by default.' );

		$r = $this->process( $this->product( $p->get_id() ), array( 'sku' => 'S6', 'sale_price' => '' ), array( 'clear_sale_when_empty' => true ) );
		$this->assertSame( 'updated', $r['status'] );
		$this->assertSame( '', $this->product( $p->get_id() )->get_sale_price() );
	}

	public function test_pricing_rules_are_applied(): void {
		$cat = self::factory()->term->create( array( 'taxonomy' => 'product_cat', 'name' => 'Gold' ) );
		$this->settings(
			array(
				'margin_percent' => 10,
				'rounding_step'  => 1000,
				'rounding_mode'  => 'up',
				'category_rules' => array( array( 'term_id' => $cat, 'margin_percent' => 25, 'max_price' => '2000000' ) ),
			)
		);
		$plain = $this->simple( 'P1', '1' );
		$gold  = $this->simple( 'P2', '1', 5, array( $cat ) );
		$r1    = $this->process( $plain, array( 'sku' => 'P1', 'regular_price' => '123456' ) );
		$this->assertSame( '136000.00', $this->product( $plain->get_id() )->get_regular_price(), '123456 * 1.10 = 135801.6 → up to 136000' );
		$r2 = $this->process( $gold, array( 'sku' => 'P2', 'regular_price' => '1,800,000' ), array( 'price_multiplier' => 1.0 ) );
		$this->assertSame( '2000000.00', $this->product( $gold->get_id() )->get_regular_price(), 'Category margin 25% then clamped to category max.' );
		$this->assertStringContainsString( 'limited', $r2['message'] );
		unset( $r1 );
	}

	public function test_variation_update_targets_the_variation(): void {
		$cat                 = self::factory()->term->create( array( 'taxonomy' => 'product_cat', 'name' => 'Shirts' ) );
		$this->settings( array( 'category_rules' => array( array( 'term_id' => $cat, 'margin_percent' => 100 ) ) ) );
		list( $parent, $vs ) = $this->variable( 'VAR', array( 'VAR-S' => '10', 'VAR-M' => '10' ), array( $cat ) );
		$r                   = $this->process( $vs[1], array( 'sku' => 'VAR-M', 'regular_price' => '20', 'stock_quantity' => '4' ) );
		$this->assertSame( 'updated', $r['status'] );
		$m = $this->product( $vs[1]->get_id() );
		$s = $this->product( $vs[0]->get_id() );
		$this->assertSame( '40.00', $m->get_regular_price(), 'Variation uses the parent category rule.' );
		$this->assertSame( 4, $m->get_stock_quantity() );
		$this->assertSame( '10', $s->get_regular_price(), 'Sibling variation untouched.' );
		$this->assertEquals( 40.0, (float) $this->product( $parent->get_id() )->get_variation_price( 'max' ), 'Parent price range synced by WooCommerce.' );
	}

	public function test_variable_parent_prices_ignored_but_stock_allowed(): void {
		list( $parent ) = $this->variable( 'VP', array( 'VP-1' => '10' ) );
		$parent->set_manage_stock( true );
		$parent->set_stock_quantity( 1 );
		$parent->save();
		$r = $this->process( $this->product( $parent->get_id() ), array( 'sku' => 'VP', 'regular_price' => '99', 'stock_quantity' => '8', 'stock_status' => 'outofstock' ) );
		$this->assertSame( 'updated', $r['status'] );
		$this->assertArrayNotHasKey( 'regular_price', $r['changes'] );
		$this->assertArrayNotHasKey( 'stock_status', $r['changes'] );
		$this->assertStringContainsString( 'variations', $r['message'] );
		$this->assertSame( 8, $this->product( $parent->get_id() )->get_stock_quantity() );
	}

	public function test_stock_management_settings_are_respected(): void {
		$unmanaged = $this->simple( 'U1', '10', null );
		$r         = $this->process( $unmanaged, array( 'sku' => 'U1', 'stock_quantity' => '3' ) );
		$this->assertSame( 'unchanged', $r['status'] );
		$this->assertStringContainsString( 'not enabled', $r['message'] );

		$r = $this->process( $unmanaged, array( 'sku' => 'U1', 'stock_status' => 'outofstock' ) );
		$this->assertSame( 'updated', $r['status'], 'Status can be set on unmanaged products.' );
		$this->assertSame( 'outofstock', $this->product( $unmanaged->get_id() )->get_stock_status() );

		$r = $this->process( $this->product( $unmanaged->get_id() ), array( 'sku' => 'U1', 'stock_quantity' => '3' ), array( 'enable_stock_management' => true ) );
		$this->assertSame( 'updated', $r['status'] );
		$this->assertTrue( $r['changes']['manage_stock']['after'] );
		$fresh = $this->product( $unmanaged->get_id() );
		$this->assertTrue( $fresh->get_manage_stock() );
		$this->assertSame( 3, $fresh->get_stock_quantity() );
		$this->assertSame( 'instock', $fresh->get_stock_status() );

		$managed = $this->simple( 'M1', '10', 2 );
		$r       = $this->process( $managed, array( 'sku' => 'M1', 'stock_status' => 'outofstock' ) );
		$this->assertSame( 'unchanged', $r['status'], 'Status of a managed product is derived from quantity.' );

		update_option( 'woocommerce_manage_stock', 'no' );
		$r = $this->process( $this->product( $managed->get_id() ), array( 'sku' => 'M1', 'stock_quantity' => '50' ) );
		$this->assertSame( 'unchanged', $r['status'] );
		$this->assertStringContainsString( 'disabled in WooCommerce', $r['message'] );
	}

	public function test_variation_with_parent_managed_stock_is_not_overwritten(): void {
		list( $parent, $vs ) = $this->variable( 'PM', array( 'PM-1' => '10' ) );
		$parent->set_manage_stock( true );
		$parent->set_stock_quantity( 20 );
		$parent->save();
		$v = $this->product( $vs[0]->get_id() );
		$v->set_manage_stock( false );
		$v->save();
		$r = $this->process( $this->product( $v->get_id() ), array( 'sku' => 'PM-1', 'stock_quantity' => '1' ) );
		$this->assertSame( 'unchanged', $r['status'] );
		$this->assertStringContainsString( 'parent', $r['message'] );
		$this->assertSame( 20, $this->product( $parent->get_id() )->get_stock_quantity() );
	}

	public function test_unsupported_and_external_types(): void {
		$g = new \WC_Product_Grouped();
		$g->set_sku( 'G1' );
		$g->save();
		$this->assertSame( 'unsupported_type', $this->process( $g, array( 'sku' => 'G1', 'regular_price' => '5' ) )['code'] );

		$e = new \WC_Product_External();
		$e->set_sku( 'E1' );
		$e->set_regular_price( '5' );
		$e->save();
		$r = $this->process( $e, array( 'sku' => 'E1', 'regular_price' => '7', 'stock_quantity' => '3' ) );
		$this->assertSame( 'updated', $r['status'] );
		$this->assertSame( '7.00', $this->product( $e->get_id() )->get_regular_price() );
		$this->assertStringContainsString( 'External', $r['message'] );
	}

	public function test_same_price_helper(): void {
		$this->assertTrue( ProductUpdater::same_price( '10', '10.00' ) );
		$this->assertFalse( ProductUpdater::same_price( '', '0' ) );
		$this->assertTrue( ProductUpdater::same_price( '', '' ) );
	}
}
