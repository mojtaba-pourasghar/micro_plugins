<?php
/**
 * Bootstrap, dependency and WooCommerce feature compatibility tests.
 *
 * @package MicroStudio\MicroSyncPro\Tests
 */

namespace MicroStudio\MicroSyncPro\Tests\Integration;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Automattic\WooCommerce\Utilities\OrderUtil;
use MicroStudio\MicroSyncPro\Database\Schema;
use MicroStudio\MicroSyncPro\Dependencies;
use MicroStudio\MicroSyncPro\Tests\TestCase;

/**
 * @covers \MicroStudio\MicroSyncPro\Compatibility
 * @covers \MicroStudio\MicroSyncPro\Dependencies
 * @covers \MicroStudio\MicroSyncPro\Database\Migrator
 */
class CompatibilityTest extends TestCase {

	public function test_declares_hpos_and_blocks_compatibility(): void {
		$basename = plugin_basename( MSP_FILE );
		$hpos     = FeaturesUtil::get_compatible_plugins_for_feature( 'custom_order_tables' );
		$this->assertContains( $basename, $hpos['compatible'] );
		$this->assertNotContains( $basename, $hpos['incompatible'] );
		$blocks = FeaturesUtil::get_compatible_plugins_for_feature( 'cart_checkout_blocks' );
		$this->assertContains( $basename, $blocks['compatible'] );
	}

	public function test_hpos_mode_matches_environment_and_products_still_sync(): void {
		$expected = '1' === getenv( 'MSP_HPOS' );
		$this->assertSame( $expected, OrderUtil::custom_orders_table_usage_is_enabled() );
		// Orders are untouched by the plugin; product sync works in both storage modes.
		$order = wc_create_order();
		$order->save();
		$p   = $this->simple( 'HPOS-1', '10', 1 );
		$s   = $this->csv_source( "sku,price,stock\nHPOS-1,12,1\n" );
		$run = $this->plugin()->run_manager()->start( $s->id, 'apply', 'manual', 1 );
		$this->assertSame( 'completed', $this->complete( $run->id )->status );
		$this->assertSame( '12.00', $this->product( $p->get_id() )->get_regular_price() );
		$this->assertInstanceOf( \WC_Order::class, wc_get_order( $order->get_id() ) );
	}

	public function test_dependencies_and_schema(): void {
		$this->assertTrue( Dependencies::woocommerce_ready() );
		global $wpdb;
		foreach ( Schema::all_tables() as $t ) {
			$this->assertSame( $t, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) );
		}
		$this->assertSame( Schema::VERSION, get_option( Schema::OPTION ) );
		$idx = $wpdb->get_results( 'SHOW INDEX FROM ' . Schema::table( 'items' ), ARRAY_A );
		$this->assertContains( 'run_row', array_column( $idx, 'Key_name' ) );
		// Running dbDelta again is a no-op (safe upgrades).
		\MicroStudio\MicroSyncPro\Database\Migrator::migrate();
		$this->assertSame( Schema::VERSION, get_option( Schema::OPTION ) );
	}

	public function test_missing_woocommerce_notice_is_clear(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		Dependencies::render_missing_notice();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'WooCommerce', $html );
	}
}
