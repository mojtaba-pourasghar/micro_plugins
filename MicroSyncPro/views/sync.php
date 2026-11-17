<?php
/**
 * Synchronization screen.
 *
 * @package MicroStudio\MicroSyncPro
 *
 * @var \MicroStudio\MicroSyncPro\Sources\Source[]     $sources
 * @var \MicroStudio\MicroSyncPro\Mapping\FieldMapper $mapper
 * @var \MicroStudio\MicroSyncPro\Sync\Run|null        $run
 * @var array<int,string>                             $names
 * @var \MicroStudio\MicroSyncPro\Sync\Run[]           $active
 * @var int                                           $preselect
 */

use MicroStudio\MicroSyncPro\Admin\View;
use MicroStudio\MicroSyncPro\Sync\Run;

defined( 'ABSPATH' ) || exit;

View::header( 'msp-sync', __( 'Synchronization', 'micro-sync-pro' ) );

$ready = array_filter(
	$sources,
	static function ( $s ) use ( $mapper ) {
		return 'active' === $s->status && $mapper->is_complete( $s->mapping );
	}
);
?>
<?php if ( empty( $sources ) ) : ?>
	<?php View::empty_state( __( 'Nothing to synchronize yet', 'micro-sync-pro' ), __( 'Add a data source and map its columns first.', 'micro-sync-pro' ), View::url( 'msp-sources', array( 'action' => 'new' ) ), __( 'Add data source', 'micro-sync-pro' ) ); ?>
<?php elseif ( empty( $ready ) ) : ?>
	<?php View::empty_state( __( 'No source is ready', 'micro-sync-pro' ), __( 'Every source needs an enabled status and a complete field mapping (SKU plus at least one field).', 'micro-sync-pro' ), View::url( 'msp-mapping' ), __( 'Open field mapping', 'micro-sync-pro' ) ); ?>
<?php else : ?>
<div class="msp-panel">
	<h2><?php esc_html_e( 'Start a synchronization', 'micro-sync-pro' ); ?></h2>
	<p class="msp-muted"><?php esc_html_e( 'Recommended: run a preview first. It reads the source, matches SKUs and shows every change without saving anything. You can then confirm and apply exactly those changes.', 'micro-sync-pro' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="msp-start-form">
		<input type="hidden" name="action" value="msp_start_run">
		<?php wp_nonce_field( 'msp_start_run' ); ?>
		<label for="msp-run-source"><?php esc_html_e( 'Data source', 'micro-sync-pro' ); ?></label>
		<select id="msp-run-source" name="source_id" required>
			<?php foreach ( $ready as $s ) : ?>
				<option value="<?php echo esc_attr( (string) $s->id ); ?>" <?php selected( $preselect, $s->id ); ?>><?php echo esc_html( $s->name ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" name="mode" value="dry_run" class="button button-primary"><span class="dashicons dashicons-visibility" aria-hidden="true"></span> <?php esc_html_e( 'Preview (dry run)', 'micro-sync-pro' ); ?></button>
		<button type="submit" name="mode" value="apply" class="button" data-msp-confirm-click="<?php esc_attr_e( 'Apply changes to products directly, without a preview?', 'micro-sync-pro' ); ?>"><?php esc_html_e( 'Synchronize now', 'micro-sync-pro' ); ?></button>
	</form>
</div>
<?php endif; ?>

<?php if ( $run ) : ?>
	<div class="msp-panel">
		<h2><?php esc_html_e( 'Current run', 'micro-sync-pro' ); ?></h2>
		<?php View::render( 'partial-progress', array( 'run' => $run, 'compact' => false, 'name' => $names[ $run->source_id ] ?? ( $run->snapshot['source_name'] ?? '' ) ) ); ?>
		<?php if ( ! $run->is_active() && '' !== $run->message ) : ?>
			<div class="notice inline <?php echo esc_attr( Run::STATUS_COMPLETED === $run->status ? 'notice-success' : 'notice-error' ); ?>"><p><?php echo esc_html( $run->message ); ?></p></div>
		<?php endif; ?>

		<?php if ( Run::MODE_DRY_RUN === $run->mode && Run::STATUS_COMPLETED === $run->status ) : ?>
			<?php if ( ! empty( $run->snapshot['applied_by'] ) ) : ?>
				<p>
					<?php esc_html_e( 'This preview has already been applied.', 'micro-sync-pro' ); ?>
					<a href="<?php echo esc_url( View::url( 'msp-history', array( 'run' => (int) $run->snapshot['applied_by'] ) ) ); ?>"><?php esc_html_e( 'View the applied run', 'micro-sync-pro' ); ?></a>
				</p>
			<?php elseif ( $run->updated > 0 ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="msp-apply-box" data-msp-confirm="<?php esc_attr_e( 'Apply the previewed changes to your products now?', 'micro-sync-pro' ); ?>">
					<input type="hidden" name="action" value="msp_apply_preview">
					<input type="hidden" name="run_id" value="<?php echo esc_attr( (string) $run->id ); ?>">
					<?php wp_nonce_field( 'msp_apply_preview' ); ?>
					<p>
						<?php
						/* translators: %s: number of products. */
						echo esc_html( sprintf( _n( '%s product will be updated. Review the changes below, then confirm.', '%s products will be updated. Review the changes below, then confirm.', $run->updated, 'micro-sync-pro' ), number_format_i18n( $run->updated ) ) );
						?>
					</p>
					<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Confirm and apply changes', 'micro-sync-pro' ); ?></button>
				</form>
			<?php else : ?>
				<p class="msp-muted"><?php esc_html_e( 'The preview found nothing to change.', 'micro-sync-pro' ); ?></p>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php View::render( 'partial-items', compact( 'run', 'items', 'items_total', 'items_page', 'filters', 'breakdown' ) + array( 'page_slug' => 'msp-sync' ) ); ?>
<?php elseif ( ! empty( $active ) ) : ?>
	<div class="msp-panel">
		<h2><?php esc_html_e( 'Running now', 'micro-sync-pro' ); ?></h2>
		<?php foreach ( $active as $a ) : ?>
			<?php View::render( 'partial-progress', array( 'run' => $a, 'compact' => true, 'name' => $names[ $a->source_id ] ?? '' ) ); ?>
		<?php endforeach; ?>
	</div>
<?php endif; ?>
<?php
View::footer();
