<?php
/**
 * Admin view helpers.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Admin;

use MicroStudio\MicroSyncPro\Sync\Run;

defined( 'ABSPATH' ) || exit;

/**
 * Shared rendering helpers (all output escaped).
 */
final class View {

	/**
	 * Render a template from /views.
	 *
	 * @param string              $template Template name (no extension).
	 * @param array<string,mixed> $vars     Variables.
	 */
	public static function render( string $template, array $vars = array() ): void {
		if ( ! preg_match( '/^[a-z0-9\-]+$/', $template ) ) {
			return;
		}
		$file = MSP_DIR . 'views/' . $template . '.php';
		if ( ! is_readable( $file ) ) {
			return;
		}
		extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		include $file;
	}

	/**
	 * Page slugs and titles.
	 *
	 * @return array<string,string>
	 */
	public static function pages(): array {
		return array(
			'msp-dashboard' => __( 'Dashboard', 'micro-sync-pro' ),
			'msp-sources'   => __( 'Data Sources', 'micro-sync-pro' ),
			'msp-sync'      => __( 'Synchronization', 'micro-sync-pro' ),
			'msp-mapping'   => __( 'Field Mapping', 'micro-sync-pro' ),
			'msp-schedule'  => __( 'Schedule', 'micro-sync-pro' ),
			'msp-history'   => __( 'History', 'micro-sync-pro' ),
			'msp-logs'      => __( 'Logs', 'micro-sync-pro' ),
			'msp-settings'  => __( 'Settings', 'micro-sync-pro' ),
		);
	}

	/**
	 * Admin URL for a page.
	 *
	 * @param string              $page Slug.
	 * @param array<string,mixed> $args Query args.
	 */
	public static function url( string $page, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Page header with navigation.
	 *
	 * @param string $current Current slug.
	 * @param string $title   Title.
	 * @param string $action  Optional action button HTML (already escaped).
	 */
	public static function header( string $current, string $title, string $action = '' ): void {
		echo '<div class="wrap msp-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1>';
		if ( '' !== $action ) {
			echo ' ' . $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts by callers.
		}
		echo '<hr class="wp-header-end">';
		echo '<nav class="nav-tab-wrapper msp-tabs" aria-label="' . esc_attr__( 'Micro Sync Pro sections', 'micro-sync-pro' ) . '">';
		foreach ( self::pages() as $slug => $label ) {
			$cls = 'nav-tab' . ( $slug === $current ? ' nav-tab-active' : '' );
			printf(
				'<a href="%1$s" class="%2$s"%3$s>%4$s</a>',
				esc_url( self::url( $slug ) ),
				esc_attr( $cls ),
				$slug === $current ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
		Notices::render();
	}

	/**
	 * Close the wrap.
	 */
	public static function footer(): void {
		echo '</div>';
	}

	/**
	 * Status labels.
	 *
	 * @return array<string,string>
	 */
	public static function status_labels(): array {
		return array(
			Run::STATUS_QUEUED      => __( 'Queued', 'micro-sync-pro' ),
			Run::STATUS_RUNNING     => __( 'Running', 'micro-sync-pro' ),
			Run::STATUS_CANCELLING  => __( 'Cancelling', 'micro-sync-pro' ),
			Run::STATUS_COMPLETED   => __( 'Completed', 'micro-sync-pro' ),
			Run::STATUS_FAILED      => __( 'Failed', 'micro-sync-pro' ),
			Run::STATUS_CANCELLED   => __( 'Cancelled', 'micro-sync-pro' ),
			Run::STATUS_INTERRUPTED => __( 'Interrupted', 'micro-sync-pro' ),
			'updated'               => __( 'Updated', 'micro-sync-pro' ),
			'would_update'          => __( 'Will update', 'micro-sync-pro' ),
			'unchanged'             => __( 'Unchanged', 'micro-sync-pro' ),
			'skipped'               => __( 'Skipped', 'micro-sync-pro' ),
			'rolled_back'           => __( 'Reverted', 'micro-sync-pro' ),
		);
	}

	/**
	 * Status badge.
	 *
	 * @param string $status Status.
	 */
	public static function badge( string $status ): string {
		$labels = self::status_labels();
		$label  = $labels[ $status ] ?? $status;
		return '<span class="msp-badge msp-badge--' . esc_attr( sanitize_html_class( $status ) ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Mode label.
	 *
	 * @param string $mode Mode.
	 */
	public static function mode_label( string $mode ): string {
		$labels = array(
			Run::MODE_DRY_RUN  => __( 'Preview (dry run)', 'micro-sync-pro' ),
			Run::MODE_APPLY    => __( 'Apply', 'micro-sync-pro' ),
			Run::MODE_ROLLBACK => __( 'Rollback', 'micro-sync-pro' ),
		);
		return $labels[ $mode ] ?? $mode;
	}

	/**
	 * Trigger label.
	 *
	 * @param string $trigger Trigger.
	 */
	public static function trigger_label( string $trigger ): string {
		$labels = array(
			'manual'   => __( 'Manual', 'micro-sync-pro' ),
			'schedule' => __( 'Scheduled', 'micro-sync-pro' ),
			'cli'      => __( 'CLI', 'micro-sync-pro' ),
		);
		return $labels[ $trigger ] ?? $trigger;
	}

	/**
	 * Reason code labels.
	 *
	 * @return array<string,string>
	 */
	public static function code_labels(): array {
		return array(
			'updated'          => __( 'Updated', 'micro-sync-pro' ),
			'preview'          => __( 'Change detected', 'micro-sync-pro' ),
			'no_changes'       => __( 'Already up to date', 'micro-sync-pro' ),
			'no_changes_notes' => __( 'Nothing applicable to change', 'micro-sync-pro' ),
			'empty_sku'        => __( 'Empty SKU', 'micro-sync-pro' ),
			'duplicate_sku'    => __( 'Duplicate SKU in source', 'micro-sync-pro' ),
			'unmatched'        => __( 'No matching product', 'micro-sync-pro' ),
			'ambiguous_match'  => __( 'Ambiguous match', 'micro-sync-pro' ),
			'unsupported_type' => __( 'Unsupported product type', 'micro-sync-pro' ),
			'invalid_value'    => __( 'Invalid value', 'micro-sync-pro' ),
			'save_error'       => __( 'Save error', 'micro-sync-pro' ),
			'product_load'     => __( 'Product load error', 'micro-sync-pro' ),
			'exception'        => __( 'Unexpected error', 'micro-sync-pro' ),
			'reverted'         => __( 'Reverted', 'micro-sync-pro' ),
			'conflict'         => __( 'Changed since sync', 'micro-sync-pro' ),
			'product_missing'  => __( 'Product deleted', 'micro-sync-pro' ),
			'nothing_to_revert' => __( 'Nothing to revert', 'micro-sync-pro' ),
		);
	}

	/**
	 * Field labels for change display.
	 *
	 * @param string $field Field.
	 */
	public static function field_label( string $field ): string {
		$labels = array(
			'regular_price'  => __( 'Regular price', 'micro-sync-pro' ),
			'sale_price'     => __( 'Sale price', 'micro-sync-pro' ),
			'stock_quantity' => __( 'Stock quantity', 'micro-sync-pro' ),
			'stock_status'   => __( 'Stock status', 'micro-sync-pro' ),
			'manage_stock'   => __( 'Manage stock', 'micro-sync-pro' ),
		);
		return $labels[ $field ] ?? $field;
	}

	/**
	 * Format a stored change value for display.
	 *
	 * @param string $field Field.
	 * @param mixed  $value Value.
	 */
	public static function change_value( string $field, $value ): string {
		if ( null === $value || '' === $value ) {
			return '—';
		}
		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'micro-sync-pro' ) : __( 'No', 'micro-sync-pro' );
		}
		if ( 'stock_status' === $field && function_exists( 'wc_get_product_stock_status_options' ) ) {
			$opts = wc_get_product_stock_status_options();
			return (string) ( $opts[ $value ] ?? $value );
		}
		if ( in_array( $field, array( 'regular_price', 'sale_price' ), true ) ) {
			return number_format_i18n( (float) $value, wc_get_price_decimals() );
		}
		if ( 'stock_quantity' === $field ) {
			return number_format_i18n( (int) $value );
		}
		return (string) $value;
	}

	/**
	 * Format a UTC MySQL datetime in the site timezone.
	 *
	 * @param string $mysql_gmt Datetime.
	 */
	public static function date( string $mysql_gmt ): string {
		if ( '' === $mysql_gmt || '0000-00-00 00:00:00' === $mysql_gmt ) {
			return '—';
		}
		$ts = strtotime( $mysql_gmt . ' UTC' );
		if ( ! $ts ) {
			return '—';
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
	}

	/**
	 * Duration between two UTC datetimes.
	 *
	 * @param string $start Start.
	 * @param string $end   End.
	 */
	public static function duration( string $start, string $end ): string {
		if ( '' === $start ) {
			return '—';
		}
		$a = strtotime( $start . ' UTC' );
		$b = '' !== $end ? strtotime( $end . ' UTC' ) : time();
		if ( ! $a || ! $b ) {
			return '—';
		}
		return human_time_diff( $a, max( $a, $b ) );
	}

	/**
	 * Empty-state box.
	 *
	 * @param string $title Title.
	 * @param string $text  Text.
	 * @param string $link  Optional link URL.
	 * @param string $label Optional link label.
	 */
	public static function empty_state( string $title, string $text, string $link = '', string $label = '' ): void {
		echo '<div class="msp-empty" role="status"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span>';
		echo '<h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $text ) . '</p>';
		if ( '' !== $link ) {
			echo '<p><a class="button button-primary" href="' . esc_url( $link ) . '">' . esc_html( $label ) . '</a></p>';
		}
		echo '</div>';
	}

	/**
	 * Pagination links.
	 *
	 * @param int $total    Total items.
	 * @param int $page     Current page.
	 * @param int $per_page Per page.
	 */
	public static function pagination( int $total, int $page, int $per_page ): void {
		$pages = (int) ceil( $total / max( 1, $per_page ) );
		if ( $pages <= 1 ) {
			return;
		}
		$links = paginate_links(
			array(
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'current'   => $page,
				'total'     => $pages,
				'prev_text' => '&lsaquo;',
				'next_text' => '&rsaquo;',
			)
		);
		echo '<div class="tablenav"><div class="tablenav-pages"><span class="displaying-num">' . esc_html(
			sprintf(
				/* translators: %s: number of items. */
				_n( '%s item', '%s items', $total, 'micro-sync-pro' ),
				number_format_i18n( $total )
			)
		) . '</span> <span class="pagination-links">' . wp_kses_post( (string) $links ) . '</span></div></div>';
	}

	/**
	 * Current page number from the query string.
	 */
	public static function current_page(): int {
		return isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}
}
