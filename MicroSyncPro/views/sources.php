<?php
/**
 * Sources list.
 *
 * @package MicroStudio\MicroSyncPro
 *
 * @var \MicroStudio\MicroSyncPro\Sources\Source[]           $sources
 * @var array<int,\MicroStudio\MicroSyncPro\Sync\Run|null>  $last
 * @var \MicroStudio\MicroSyncPro\Mapping\FieldMapper       $mapper
 */

use MicroStudio\MicroSyncPro\Admin\View;
use MicroStudio\MicroSyncPro\Scheduling\Scheduler;
use MicroStudio\MicroSyncPro\Sources\SourceTypes;

defined( 'ABSPATH' ) || exit;

$add = '<a href="' . esc_url( View::url( 'msp-sources', array( 'action' => 'new' ) ) ) . '" class="page-title-action">' . esc_html__( 'Add data source', 'micro-sync-pro' ) . '</a>';
View::header( 'msp-sources', __( 'Data Sources', 'micro-sync-pro' ), $add );

if ( empty( $sources ) ) {
	View::empty_state(
		__( 'No data sources yet', 'micro-sync-pro' ),
		__( 'A data source is a CSV or Excel file you upload, or a CSV/JSON address of your supplier.', 'micro-sync-pro' ),
		View::url( 'msp-sources', array( 'action' => 'new' ) ),
		__( 'Add data source', 'micro-sync-pro' )
	);
	View::footer();
	return;
}
$types     = SourceTypes::labels();
$schedules = Scheduler::labels();
?>
<div class="msp-table-wrap">
<table class="widefat striped msp-table">
	<thead>
		<tr>
			<th scope="col"><?php esc_html_e( 'Name', 'micro-sync-pro' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Type', 'micro-sync-pro' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Location', 'micro-sync-pro' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Mapping', 'micro-sync-pro' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Schedule', 'micro-sync-pro' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Last run', 'micro-sync-pro' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Actions', 'micro-sync-pro' ); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php foreach ( $sources as $source ) : ?>
		<?php $run = $last[ $source->id ] ?? null; ?>
		<tr>
			<td>
				<strong><a href="<?php echo esc_url( View::url( 'msp-sources', array( 'action' => 'edit', 'source' => $source->id ) ) ); ?>"><?php echo esc_html( $source->name ); ?></a></strong>
				<?php if ( 'active' !== $source->status ) : ?>
					<span class="msp-badge msp-badge--cancelled"><?php esc_html_e( 'Disabled', 'micro-sync-pro' ); ?></span>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( $types[ $source->type ] ?? $source->type ); ?></td>
			<td><bdi class="msp-mono"><?php echo esc_html( $source->is_remote() ? (string) $source->opt( 'url_display', '' ) : (string) $source->opt( 'original_name', '' ) ); ?></bdi></td>
			<td>
				<?php if ( $mapper->is_complete( $source->mapping ) ) : ?>
					<span class="dashicons dashicons-yes-alt msp-ok" aria-hidden="true"></span> <?php esc_html_e( 'Ready', 'micro-sync-pro' ); ?>
				<?php else : ?>
					<a href="<?php echo esc_url( View::url( 'msp-mapping', array( 'source' => $source->id ) ) ); ?>"><span class="dashicons dashicons-warning msp-warn" aria-hidden="true"></span> <?php esc_html_e( 'Configure', 'micro-sync-pro' ); ?></a>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( $schedules[ $source->schedule ] ?? $source->schedule ); ?></td>
			<td>
				<?php if ( $run ) : ?>
					<a href="<?php echo esc_url( View::url( 'msp-history', array( 'run' => $run->id ) ) ); ?>">#<?php echo esc_html( (string) $run->id ); ?></a>
					<?php echo View::badge( $run->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<br><small><?php echo esc_html( View::date( $run->created_at ) ); ?></small>
				<?php else : ?>
					<span class="msp-muted"><?php esc_html_e( 'Never', 'micro-sync-pro' ); ?></span>
				<?php endif; ?>
			</td>
			<td class="msp-actions">
				<a class="button button-small" href="<?php echo esc_url( View::url( 'msp-sources', array( 'action' => 'edit', 'source' => $source->id ) ) ); ?>"><?php esc_html_e( 'Edit', 'micro-sync-pro' ); ?></a>
				<a class="button button-small" href="<?php echo esc_url( View::url( 'msp-mapping', array( 'source' => $source->id ) ) ); ?>"><?php esc_html_e( 'Mapping', 'micro-sync-pro' ); ?></a>
				<a class="button button-small button-primary" href="<?php echo esc_url( View::url( 'msp-sync', array( 'source' => $source->id ) ) ); ?>"><?php esc_html_e( 'Synchronize', 'micro-sync-pro' ); ?></a>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="msp-inline-form" data-msp-confirm="<?php esc_attr_e( 'Delete this data source? Its history is kept.', 'micro-sync-pro' ); ?>">
					<input type="hidden" name="action" value="msp_delete_source">
					<input type="hidden" name="source_id" value="<?php echo esc_attr( (string) $source->id ); ?>">
					<?php wp_nonce_field( 'msp_delete_source' ); ?>
					<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'micro-sync-pro' ); ?></button>
				</form>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
</div>
<?php
View::footer();
