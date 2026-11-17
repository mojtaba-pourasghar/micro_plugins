<?php
/**
 * Dashboard view.
 *
 * @package MicroStudio\MicroSyncPro
 *
 * @var \MicroStudio\MicroSyncPro\Sources\Source[] $sources
 * @var array<int,string>                         $names
 * @var array<string,int>                         $stats
 * @var \MicroStudio\MicroSyncPro\Sync\Run[]      $active
 * @var \MicroStudio\MicroSyncPro\Sync\Run[]      $recent
 * @var array<int,object>                         $changes
 * @var array<int,array>                          $system
 * @var int                                       $scheduled
 */

use MicroStudio\MicroSyncPro\Admin\View;

defined( 'ABSPATH' ) || exit;

View::header( 'msp-dashboard', __( 'Micro Sync Pro', 'micro-sync-pro' ) );

if ( empty( $sources ) ) :
	View::empty_state(
		__( 'Welcome to Micro Sync Pro', 'micro-sync-pro' ),
		__( 'Add your first data source (CSV, Excel or a supplier URL), map its columns and run a preview. Products are matched by SKU and are never created automatically.', 'micro-sync-pro' ),
		View::url( 'msp-sources', array( 'action' => 'new' ) ),
		__( 'Add a data source', 'micro-sync-pro' )
	);
endif;
?>
<div class="msp-cards" role="list">
	<div class="msp-card" role="listitem">
		<span class="msp-card__label"><?php esc_html_e( 'Data sources', 'micro-sync-pro' ); ?></span>
		<span class="msp-card__value"><?php echo esc_html( number_format_i18n( count( $sources ) ) ); ?></span>
		<span class="msp-card__hint">
		<?php
		/* translators: %s: number of scheduled sources. */
		echo esc_html( sprintf( __( '%s scheduled', 'micro-sync-pro' ), number_format_i18n( $scheduled ) ) );
		?>
		</span>
	</div>
	<div class="msp-card" role="listitem">
		<span class="msp-card__label"><?php esc_html_e( 'Runs (30 days)', 'micro-sync-pro' ); ?></span>
		<span class="msp-card__value"><?php echo esc_html( number_format_i18n( (int) ( $stats['runs'] ?? 0 ) ) ); ?></span>
		<span class="msp-card__hint">
		<?php
		/* translators: %s: number of failed runs. */
		echo esc_html( sprintf( __( '%s failed or interrupted', 'micro-sync-pro' ), number_format_i18n( (int) ( $stats['failed_runs'] ?? 0 ) ) ) );
		?>
		</span>
	</div>
	<div class="msp-card msp-card--success" role="listitem">
		<span class="msp-card__label"><?php esc_html_e( 'Products updated (30 days)', 'micro-sync-pro' ); ?></span>
		<span class="msp-card__value"><?php echo esc_html( number_format_i18n( (int) ( $stats['updated'] ?? 0 ) ) ); ?></span>
	</div>
	<div class="msp-card msp-card--error" role="listitem">
		<span class="msp-card__label"><?php esc_html_e( 'Failed records (30 days)', 'micro-sync-pro' ); ?></span>
		<span class="msp-card__value"><?php echo esc_html( number_format_i18n( (int) ( $stats['failed_records'] ?? 0 ) ) ); ?></span>
	</div>
</div>

<?php if ( ! empty( $active ) ) : ?>
<div class="msp-panel">
	<h2><?php esc_html_e( 'Running now', 'micro-sync-pro' ); ?></h2>
	<?php foreach ( $active as $run ) : ?>
		<?php View::render( 'partial-progress', array( 'run' => $run, 'compact' => true, 'name' => $names[ $run->source_id ] ?? ( $run->snapshot['source_name'] ?? '' ) ) ); ?>
	<?php endforeach; ?>
</div>
<?php endif; ?>

<div class="msp-grid">
	<div class="msp-panel">
		<h2><?php esc_html_e( 'Recent runs', 'micro-sync-pro' ); ?></h2>
		<?php if ( empty( $recent ) ) : ?>
			<p class="msp-muted"><?php esc_html_e( 'No synchronization has been run yet.', 'micro-sync-pro' ); ?></p>
		<?php else : ?>
			<?php View::render( 'partial-runs-table', array( 'runs' => $recent, 'names' => $names, 'compact' => true ) ); ?>
			<p><a href="<?php echo esc_url( View::url( 'msp-history' ) ); ?>"><?php esc_html_e( 'View full history', 'micro-sync-pro' ); ?> &rarr;</a></p>
		<?php endif; ?>
	</div>
	<div class="msp-panel">
		<h2><?php esc_html_e( 'Latest product changes', 'micro-sync-pro' ); ?></h2>
		<?php if ( empty( $changes ) ) : ?>
			<p class="msp-muted"><?php esc_html_e( 'No product has been changed yet.', 'micro-sync-pro' ); ?></p>
		<?php else : ?>
			<table class="widefat striped msp-table">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'SKU', 'micro-sync-pro' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Changes', 'micro-sync-pro' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Date', 'micro-sync-pro' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $changes as $item ) : ?>
					<tr>
						<td>
							<?php if ( $item->product_id && get_edit_post_link( (int) $item->product_id ) ) : ?>
								<a href="<?php echo esc_url( (string) get_edit_post_link( (int) $item->product_id ) ); ?>"><bdi><?php echo esc_html( $item->sku ); ?></bdi></a>
							<?php else : ?>
								<bdi><?php echo esc_html( $item->sku ); ?></bdi>
							<?php endif; ?>
						</td>
						<td><?php View::render( 'partial-changes', array( 'changes' => json_decode( (string) $item->changes, true ) ) ); ?></td>
						<td><?php echo esc_html( View::date( (string) $item->created_at ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</div>

<div class="msp-panel">
	<h2><?php esc_html_e( 'System status', 'micro-sync-pro' ); ?></h2>
	<table class="widefat striped msp-table msp-status-table">
		<tbody>
		<?php foreach ( $system as $row ) : ?>
			<tr>
				<th scope="row"><?php echo esc_html( $row['label'] ); ?></th>
				<td>
					<span class="dashicons <?php echo esc_attr( $row['ok'] ? 'dashicons-yes-alt msp-ok' : 'dashicons-warning msp-warn' ); ?>" aria-hidden="true"></span>
					<?php echo esc_html( $row['value'] ); ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</div>
<?php
View::footer();
