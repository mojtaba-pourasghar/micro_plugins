<?php
/**
 * Dependency checks.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro;

defined( 'ABSPATH' ) || exit;

/**
 * Checks that WooCommerce is available in a supported version.
 */
final class Dependencies {

	/**
	 * Whether WooCommerce is loaded and recent enough.
	 */
	public static function woocommerce_ready(): bool {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_product' ) ) {
			return false;
		}
		$version = defined( 'WC_VERSION' ) ? WC_VERSION : '0';
		return version_compare( $version, MSP_MIN_WC, '>=' );
	}

	/**
	 * Admin notice when WooCommerce is missing.
	 */
	public static function render_missing_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		if ( class_exists( 'WooCommerce' ) ) {
			$message = sprintf(
				/* translators: 1: required WooCommerce version, 2: installed version. */
				__( 'Micro Sync Pro requires WooCommerce %1$s or newer (installed: %2$s). Synchronization is disabled until WooCommerce is updated.', 'micro-sync-pro' ),
				MSP_MIN_WC,
				defined( 'WC_VERSION' ) ? WC_VERSION : '?'
			);
		} else {
			$message = __( 'Micro Sync Pro requires WooCommerce to be installed and active. Synchronization is disabled until WooCommerce is activated.', 'micro-sync-pro' );
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Micro Sync Pro', 'micro-sync-pro' ) . ':</strong> ' . esc_html( $message ) . '</p></div>';
	}
}
