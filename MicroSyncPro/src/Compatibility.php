<?php
/**
 * WooCommerce feature compatibility declarations.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro;

defined( 'ABSPATH' ) || exit;

/**
 * Declares compatibility with WooCommerce features.
 *
 * The plugin never reads or writes orders and only touches products through
 * the WooCommerce CRUD API, so it is compatible with High-Performance Order
 * Storage (custom_order_tables) and with the block-based cart/checkout.
 */
final class Compatibility {

	/**
	 * Hooked to before_woocommerce_init.
	 */
	public static function declare_features(): void {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			return;
		}
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', MSP_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', MSP_FILE, true );
	}
}
