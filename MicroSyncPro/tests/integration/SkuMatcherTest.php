<?php
/**
 * SKU matching tests.
 *
 * @package MicroStudio\MicroSyncPro\Tests
 */

namespace MicroStudio\MicroSyncPro\Tests\Integration;

use MicroStudio\MicroSyncPro\Matching\SkuMatcher;
use MicroStudio\MicroSyncPro\Tests\TestCase;

/**
 * @covers \MicroStudio\MicroSyncPro\Matching\SkuMatcher
 */
class SkuMatcherTest extends TestCase {

	public function test_exact_matching_of_products_and_variations(): void {
		$a                   = $this->simple( 'ABC-1' );
		list( $parent, $vs ) = $this->variable( 'TSHIRT', array( 'TSHIRT-R' => '10', 'TSHIRT-B' => '12' ) );
		$res                 = ( new SkuMatcher() )->match( array( 'ABC-1', ' TSHIRT-B ', 'TSHIRT', 'missing', '', 'abc-1' ) );
		$this->assertSame( array( $a->get_id() ), $res['ABC-1'] );
		$this->assertSame( array( $vs[1]->get_id() ), $res['TSHIRT-B'], 'Incoming SKU is trimmed and matches the variation.' );
		$this->assertSame( array( $parent->get_id() ), $res['TSHIRT'] );
		$this->assertSame( array(), $res['missing'] );
		$this->assertSame( array(), $res['abc-1'], 'Matching is case-sensitive (exact).' );
		$this->assertArrayNotHasKey( '', $res, 'Empty SKUs are ignored.' );
	}

	public function test_duplicate_matching_products_are_all_returned(): void {
		$a = $this->simple( 'DUP-1' );
		// WooCommerce prevents duplicate SKUs through the API; simulate legacy/imported data.
		$b = $this->simple( 'TMP-DUP' );
		update_post_meta( $b->get_id(), '_sku', 'DUP-1' );
		$res = ( new SkuMatcher() )->match( array( 'DUP-1' ) );
		$this->assertEqualsCanonicalizing( array( $a->get_id(), $b->get_id() ), $res['DUP-1'] );
	}

	public function test_trashed_products_and_variations_of_trashed_parents_are_ignored(): void {
		$a = $this->simple( 'TRASH-1' );
		wp_trash_post( $a->get_id() );
		list( $parent, $vs ) = $this->variable( 'TP', array( 'TP-1' => '5' ) );
		wp_trash_post( $parent->get_id() );
		$res = ( new SkuMatcher() )->match( array( 'TRASH-1', 'TP-1' ) );
		$this->assertSame( array(), $res['TRASH-1'] );
		$this->assertSame( array(), $res['TP-1'] );
	}

	public function test_large_sku_list_is_chunked(): void {
		$p    = $this->simple( 'CHUNK-777' );
		$skus = array();
		for ( $i = 0; $i < 650; $i++ ) {
			$skus[] = 'CHUNK-' . $i;
		}
		$res = ( new SkuMatcher() )->match( $skus );
		$this->assertCount( 650, $res );
		$this->assertSame( array(), $res['CHUNK-1'] );
		$skus[] = 'CHUNK-777';
		$this->assertSame( array( $p->get_id() ), ( new SkuMatcher() )->match( $skus )['CHUNK-777'] );
	}
}
