<?php
/**
 * Per-record results with filters.
 *
 * @package MicroStudio\MicroSyncPro
 *
 * @var \MicroStudio\MicroSyncPro\Sync\Run $run
 * @var array<int,object>                  $items
 * @var int                                $items_total
 * @var int                                $items_page
 * @var array<string,string>               $filters
 * @var array<int,object>                  $breakdown
 * @var string                             $page_slug
 */

use MicroStudio\MicroSyncPro\Admin\View;

defined( 'ABSPATH' ) || exit;

$codes    = View::code_labels();
$statuses = array( 'updated', 'would_update', 'rolled_back', 'unchanged', 'skipped', 'failed' );
?>
<div class="msp-panel">
	<h2><?php esc_html_e( 'Record results', 'micro-sync-pro' ); ?></h2>
	<?php if ( ! empty( $breakdown ) ) : ?>
		<ul class="msp-breakdown">
		<?php foreach ( $breakdown as $b ) : ?>
			<li>
				<a href="<?php echo esc_url( View::url( $page_slug, array( 'run' => $run->id, 'item_status' => $b->status, 'code' => $b->code ) ) ); ?>">
					<?php echo View::badge( (string) $b->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo esc_html( $codes[ $b->code ] ?? $b->code ); ?>
					<strong><?php echo esc_html( number_format_i18n( (int) $b->c ) ); ?></strong>
				</a>
			</li>
		<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<form method="get" class="msp-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>">
		<input type="hidden" name="run" value="<?php echo esc_attr( (string) $run->id ); ?>">
		<label for="msp-item-status"><?php esc_html_e( 'Status', 'micro-sync-pro' ); ?></label>
		<select id="msp-item-status" name="item_status">
			<option value=""><?php esc_html_e( 'All', 'micro-sync-pro' ); ?></option>
			<?php foreach ( $statuses as $st ) : ?>
				<option value="<?php echo esc_attr( $st ); ?>" <?php selected( $filters['status'], $st ); ?>><?php echo esc_html( View::status_labels()[ $st ] ?? $st ); ?></option>
			<?php endforeach; ?>
		</select>
		<label for="msp-item-code"><?php esc_html_e( 'Reason', 'micro-sync-pro' ); ?></label>
		<select id="msp-item-code" name="code">
			<option value=""><?php esc_html_e( 'All', 'micro-sync-pro' ); ?></option>
			<?php foreach ( $codes as $code => $label ) : ?>
				<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $filters['code'], $code ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<label for="msp-item-search" class="screen-reader-text"><?php esc_html_e( 'Search SKU', 'micro-sync-pro' ); ?></label>
		<input type="search" id="msp-item-search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search SKU…', 'micro-sync-pro' ); ?>">
		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'micro-sync-pro' ); ?></button>
	</form>

	<?php if ( empty( $items ) ) : ?>
		<p class="msp-muted" role="status">
			<?php
			echo esc_html(
				$run->is_active()
					? __( 'Results appear here as records are processed.', 'micro-sync-pro' )
					: __( 'No records match the current filter.', 'micro-sync-pro' )
			);
			?>
		</p>
	<?php else : ?>
		<div class="msp-table-wrap">
		<table class="widefat striped msp-table">
			<thead>
				<tr>
					<th scope="col" class="num"><?php esc_html_e( 'Row', 'micro-sync-pro' ); ?></th>
					<th scope="col"><?php esc_html_e( 'SKU', 'micro-sync-pro' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Product', 'micro-sync-pro' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Result', 'micro-sync-pro' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Changes', 'micro-sync-pro' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Details', 'micro-sync-pro' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $items as $item ) : ?>
				<tr class="msp-row--<?php echo esc_attr( sanitize_html_class( (string) $item->status ) ); ?>">
					<td class="num"><?php echo esc_html( 'rollback' === $run->mode ? '—' : number_format_i18n( (int) $item->row_num ) ); ?></td>
					<td><bdi><?php echo esc_html( (string) $item->sku ); ?></bdi></td>
					<td>
						<?php
						$pid = (int) $item->product_id;
						if ( $pid ) {
							$title = get_the_title( $pid );
							$link  = get_edit_post_link( wp_get_post_parent_id( $pid ) ? wp_get_post_parent_id( $pid ) : $pid );
							if ( $link ) {
								echo '<a href="' . esc_url( (string) $link ) . '">' . esc_html( '' !== $title ? $title : '#' . $pid ) . '</a>';
							} else {
								echo esc_html( '#' . $pid );
							}
						} else {
							echo '<span class="msp-muted">—</span>';
						}
						?>
					</td>
					<td><?php echo View::badge( (string) $item->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><br><small><?php echo esc_html( $codes[ $item->code ] ?? (string) $item->code ); ?></small></td>
					<td><?php View::render( 'partial-changes', array( 'changes' => json_decode( (string) $item->changes, true ) ) ); ?></td>
					<td class="msp-message"><?php echo esc_html( (string) $item->message ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php View::pagination( $items_total, $items_page, \MicroStudio\MicroSyncPro\Admin\Pages::PER_PAGE ); ?>
	<?php endif; ?>
</div>
