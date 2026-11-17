<?php
/**
 * Base test case with helpers.
 *
 * @package MicroStudio\MicroSyncPro\Tests
 */

namespace MicroStudio\MicroSyncPro\Tests;

use MicroStudio\MicroSyncPro\Plugin;
use MicroStudio\MicroSyncPro\Settings\Settings;
use MicroStudio\MicroSyncPro\Sources\Source;
use MicroStudio\MicroSyncPro\Sources\SourceTypes;
use MicroStudio\MicroSyncPro\Sync\Run;

/**
 * Shared helpers.
 */
abstract class TestCase extends \WP_UnitTestCase {

	/** @var string[] Files created during the test. */
	protected $created_files = array();

	/**
	 * Reset plugin state.
	 */
	public function set_up() {
		parent::set_up();
		Plugin::instance()->reset_services();
		delete_option( Settings::OPTION );
		update_option( 'woocommerce_manage_stock', 'yes' );
		update_option( 'woocommerce_price_num_decimals', 2 );
		// Process runs inline: tests drive them with JobQueue::work().
		add_filter( 'micro_sync_pro_use_action_scheduler', '__return_false' );
		add_filter( 'pre_http_request', array( $this, 'block_real_http' ), 1000, 3 );
	}

	/**
	 * Cleanup.
	 */
	public function tear_down() {
		foreach ( $this->created_files as $f ) {
			if ( is_file( $f ) ) {
				unlink( $f );
			}
		}
		remove_all_filters( 'micro_sync_pro_use_action_scheduler' );
		remove_all_filters( 'pre_http_request' );
		wp_clear_scheduled_hook( 'msp_process_run' );
		parent::tear_down();
	}

	/**
	 * Fail loudly if a test would hit the network.
	 *
	 * @param mixed  $pre  Pre.
	 * @param array  $args Args.
	 * @param string $url  URL.
	 * @return mixed
	 */
	public function block_real_http( $pre, $args, $url ) {
		if ( false === $pre ) {
			return new \WP_Error( 'blocked', 'Real HTTP blocked in tests: ' . $url );
		}
		return $pre;
	}

	/**
	 * Plugin.
	 */
	protected function plugin(): Plugin {
		return Plugin::instance();
	}

	/**
	 * Save settings.
	 *
	 * @param array<string,mixed> $settings Settings.
	 */
	protected function settings( array $settings ): void {
		$this->plugin()->settings()->save( $settings );
	}

	/**
	 * Create a simple product.
	 *
	 * @param string     $sku    SKU.
	 * @param string     $price  Regular price.
	 * @param int|null   $stock  Stock (null = not managed).
	 * @param int[]      $cats   Category ids.
	 */
	protected function simple( string $sku, string $price = '100', ?int $stock = 5, array $cats = array() ): \WC_Product_Simple {
		$p = new \WC_Product_Simple();
		$p->set_name( 'Product ' . $sku );
		$p->set_sku( $sku );
		$p->set_regular_price( $price );
		if ( null !== $stock ) {
			$p->set_manage_stock( true );
			$p->set_stock_quantity( $stock );
		}
		if ( $cats ) {
			$p->set_category_ids( $cats );
		}
		$p->save();
		return $p;
	}

	/**
	 * Create a variable product with variations.
	 *
	 * @param string               $parent_sku Parent SKU.
	 * @param array<string,string> $variations sku => price.
	 * @param int[]                $cats       Category ids.
	 * @return array{0:\WC_Product_Variable,1:\WC_Product_Variation[]}
	 */
	protected function variable( string $parent_sku, array $variations, array $cats = array() ): array {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Variable ' . $parent_sku );
		$parent->set_sku( $parent_sku );
		if ( $cats ) {
			$parent->set_category_ids( $cats );
		}
		$parent->save();
		$out = array();
		foreach ( $variations as $sku => $price ) {
			$v = new \WC_Product_Variation();
			$v->set_parent_id( $parent->get_id() );
			$v->set_sku( $sku );
			$v->set_regular_price( $price );
			$v->set_manage_stock( true );
			$v->set_stock_quantity( 1 );
			$v->save();
			$out[] = $v;
		}
		return array( wc_get_product( $parent->get_id() ), $out );
	}

	/**
	 * Write a temp file in the plugin storage and return its stored name.
	 *
	 * @param string $content Content.
	 * @param string $ext     Extension.
	 */
	protected function stored_file( string $content, string $ext = 'csv' ): string {
		$storage = $this->plugin()->storage();
		$storage->ensure_directory();
		$name = $storage->new_name( $ext, 'u' );
		file_put_contents( $storage->path( $name ), $content );
		$this->created_files[] = $storage->path( $name );
		return $name;
	}

	/**
	 * Temporary file path outside storage.
	 *
	 * @param string $content Content.
	 * @param string $suffix  Suffix.
	 */
	protected function temp_file( string $content, string $suffix = '.csv' ): string {
		$path = tempnam( sys_get_temp_dir(), 'msp' ) . $suffix;
		file_put_contents( $path, $content );
		$this->created_files[] = $path;
		return $path;
	}

	/**
	 * Default mapping.
	 *
	 * @param string[] $fields Fields to enable.
	 * @return array<string,array{column:string,enabled:bool}>
	 */
	protected function mapping( array $fields = array( 'regular_price', 'stock_quantity' ) ): array {
		$cols = array(
			'sku'            => 'sku',
			'regular_price'  => 'price',
			'sale_price'     => 'sale',
			'stock_quantity' => 'stock',
			'stock_status'   => 'status',
		);
		$m    = array();
		foreach ( $cols as $f => $c ) {
			$m[ $f ] = array(
				'column'  => $c,
				'enabled' => 'sku' === $f || in_array( $f, $fields, true ),
			);
		}
		return $m;
	}

	/**
	 * Create a CSV upload source.
	 *
	 * @param string              $csv     CSV.
	 * @param array<string,mixed> $mapping Mapping.
	 * @param array<string,mixed> $config  Extra config.
	 */
	protected function csv_source( string $csv, ?array $mapping = null, array $config = array() ): Source {
		$s          = new Source();
		$s->name    = 'Test source';
		$s->type    = SourceTypes::CSV_UPLOAD;
		$s->config  = array_merge(
			array(
				'file'          => $this->stored_file( $csv ),
				'original_name' => 'test.csv',
			),
			$config
		);
		$s->mapping = $mapping ?? $this->mapping();
		$s->columns = array( 'sku', 'price', 'sale', 'stock', 'status' );
		$this->plugin()->sources()->save( $s );
		return $s;
	}

	/**
	 * Drive a run until it finishes.
	 *
	 * @param int $run_id Run.
	 * @param int $max    Max slices.
	 */
	protected function complete( int $run_id, int $max = 50 ): Run {
		for ( $i = 0; $i < $max; $i++ ) {
			$run = $this->plugin()->queue()->work( $run_id, 30 );
			if ( $run && ! $run->is_active() ) {
				return $run;
			}
		}
		$this->fail( 'Run did not complete.' );
	}

	/**
	 * Fresh product.
	 *
	 * @param int $id Id.
	 */
	protected function product( int $id ): \WC_Product {
		wp_cache_flush();
		return wc_get_product( $id );
	}

	/**
	 * Items of a run indexed by SKU.
	 *
	 * @param int $run_id Run.
	 * @return array<string,object>
	 */
	protected function items_by_sku( int $run_id ): array {
		$out = array();
		foreach ( $this->plugin()->items()->paginate( $run_id, array(), 1, 1000 )['items'] as $i ) {
			$out[ $i->sku . '#' . $i->row_num ] = $i;
		}
		return $out;
	}

	/**
	 * First item for a SKU.
	 *
	 * @param int    $run_id Run.
	 * @param string $sku    SKU.
	 */
	protected function item( int $run_id, string $sku ) {
		foreach ( $this->plugin()->items()->paginate( $run_id, array(), 1, 1000 )['items'] as $i ) {
			if ( $i->sku === $sku ) {
				return $i;
			}
		}
		$this->fail( 'No item for SKU ' . $sku );
	}
}
