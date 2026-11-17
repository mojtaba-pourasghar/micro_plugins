<?php
/**
 * Run detail.
 *
 * @package MicroStudio\MicroSyncPro
 *
 * @var \MicroStudio\MicroSyncPro\Sync\Run      $run
 * @var \MicroStudio\MicroSyncPro\Sync\Run|null $parent
 * @var array<int,string>                       $names
 */

use MicroStudio\MicroSyncPro\Admin\View;
use MicroStudio\MicroSyncPro\Sync\Run;

defined( 'ABSPATH' ) || exit;

/* translators: %d: run id. */
View::header( 'msp-history', sprintf( __( 'Run #%d', 'micro-sync-pro' ), $run->id ) );
$post_url = admin_url( 'admin-post.php' );
$user     = $run->user_id ? get_userdata( $run->user_id ) : false;
?>
<p><a href="<?php echo esc_url( View::url( 'msp-history' ) ); ?>">&larr; <?php esc_html_e( 'Back to history', 'micro-sync-pro' ); ?></a></p>

<?php if ( $run->is_active() ) : ?>
	<div class="msp-panel"><?php View::render( 'partial-progress', array( 'run' => $run, 'compact' => false, 'name' => $names[ $run->source_id ] ?? ( $run->snapshot['source_name'] ?? '' ) ) ); ?></div>
<?php endif; ?>

<div class="msp-panel">
	<h2><?php esc_html_e( 'Summary', 'micro-sync-pro' ); ?></h2>
	<table class="widefat striped msp-table msp-summary">
		<tbody>
			<tr><th scope="row"><?php esc_html_e( 'Execution ID', 'micro-sync-pro' ); ?></th><td><code><?php echo esc_html( $run->uuid ); ?></code></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Source', 'micro-sync-pro' ); ?></th><td><?php echo esc_html( ( $names[ $run->source_id ] ?? ( $run->snapshot['source_name'] ?? __( '(deleted source)', 'micro-sync-pro' ) ) ) . ' (#' . $run->source_id . ')' ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Mode', 'micro-sync-pro' ); ?></th><td><?php echo esc_html( View::mode_label( $run->mode ) ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Status', 'micro-sync-pro' ); ?></th><td><?php echo View::badge( $run->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Trigger', 'micro-sync-pro' ); ?></th><td><?php echo esc_html( View::trigger_label( $run->trigger ) . ( $user ? ' — ' . $user->display_name : '' ) ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Start time', 'micro-sync-pro' ); ?></th><td><?php echo esc_html( View::date( $run->started_at ) ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'End time', 'micro-sync-pro' ); ?></th><td><?php echo esc_html( View::date( $run->finished_at ) ); ?> (<?php echo esc_html( View::duration( $run->started_at, $run->finished_at ) ); ?>)</td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Total records', 'micro-sync-pro' ); ?></th><td><?php echo esc_html( number_format_i18n( $run->total ) ); ?></td></tr>
			<tr><th scope="row"><?php echo esc_html( Run::MODE_DRY_RUN === $run->mode ? __( 'Would be updated', 'micro-sync-pro' ) : __( 'Successful updates', 'micro-sync-pro' ) ); ?></th><td><?php echo esc_html( number_format_i18n( $run->updated ) ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Unchanged', 'micro-sync-pro' ); ?></th><td><?php echo esc_html( number_format_i18n( $run->unchanged ) ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Skipped records', 'micro-sync-pro' ); ?></th><td><?php echo esc_html( number_format_i18n( $run->skipped ) ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Failed records', 'micro-sync-pro' ); ?></th><td><?php echo esc_html( number_format_i18n( $run->failed ) ); ?></td></tr>
			<?php if ( '' !== $run->message ) : ?>
				<tr><th scope="row"><?php esc_html_e( 'Message', 'micro-sync-pro' ); ?></th><td><?php echo esc_html( $run->message ); ?></td></tr>
			<?php endif; ?>
			<?php if ( $parent ) : ?>
				<tr><th scope="row"><?php echo esc_html( Run::MODE_ROLLBACK === $run->mode ? __( 'Rolled back run', 'micro-sync-pro' ) : __( 'Based on preview', 'micro-sync-pro' ) ); ?></th><td><a href="<?php echo esc_url( View::url( 'msp-history', array( 'run' => $parent->id ) ) ); ?>">#<?php echo esc_html( (string) $parent->id ); ?></a></td></tr>
			<?php endif; ?>
			<?php if ( ! empty( $run->snapshot['rolled_back_by'] ) ) : ?>
				<tr><th scope="row"><?php esc_html_e( 'Rolled back by', 'micro-sync-pro' ); ?></th><td><a href="<?php echo esc_url( View::url( 'msp-history', array( 'run' => (int) $run->snapshot['rolled_back_by'] ) ) ); ?>">#<?php echo esc_html( (string) (int) $run->snapshot['rolled_back_by'] ); ?></a></td></tr>
			<?php endif; ?>
		</tbody>
	</table>

	<div class="msp-toolbar">
		<?php if ( $run->can_rollback() ) : ?>
			<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="msp-inline-form" data-msp-confirm="<?php esc_attr_e( 'Restore the previous values of all products updated by this run? Products changed since then are skipped.', 'micro-sync-pro' ); ?>">
				<input type="hidden" name="action" value="msp_rollback_run">
				<input type="hidden" name="run_id" value="<?php echo esc_attr( (string) $run->id ); ?>">
				<?php wp_nonce_field( 'msp_rollback_run' ); ?>
				<button type="submit" class="button"><span class="dashicons dashicons-undo" aria-hidden="true"></span> <?php esc_html_e( 'Roll back this run', 'micro-sync-pro' ); ?></button>
			</form>
		<?php endif; ?>
		<?php if ( Run::STATUS_INTERRUPTED === $run->status ) : ?>
			<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="msp-inline-form">
				<input type="hidden" name="action" value="msp_resume_run">
				<input type="hidden" name="run_id" value="<?php echo esc_attr( (string) $run->id ); ?>">
				<?php wp_nonce_field( 'msp_resume_run' ); ?>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Resume', 'micro-sync-pro' ); ?></button>
			</form>
		<?php endif; ?>
		<?php if ( $run->is_active() ) : ?>
			<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="msp-inline-form" data-msp-confirm="<?php esc_attr_e( 'Stop this synchronization after the current batch?', 'micro-sync-pro' ); ?>">
				<input type="hidden" name="action" value="msp_cancel_run">
				<input type="hidden" name="run_id" value="<?php echo esc_attr( (string) $run->id ); ?>">
				<?php wp_nonce_field( 'msp_cancel_run' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Cancel', 'micro-sync-pro' ); ?></button>
			</form>
		<?php endif; ?>
		<?php if ( Run::MODE_DRY_RUN === $run->mode && Run::STATUS_COMPLETED === $run->status && empty( $run->snapshot['applied_by'] ) && $run->updated > 0 ) : ?>
			<a class="button button-primary" href="<?php echo esc_url( View::url( 'msp-sync', array( 'run' => $run->id ) ) ); ?>"><?php esc_html_e( 'Review and apply', 'micro-sync-pro' ); ?></a>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="msp-inline-form">
			<input type="hidden" name="action" value="msp_export_run">
			<input type="hidden" name="run_id" value="<?php echo esc_attr( (string) $run->id ); ?>">
			<?php wp_nonce_field( 'msp_export_run' ); ?>
			<button type="submit" class="button"><span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e( 'Export results (CSV)', 'micro-sync-pro' ); ?></button>
		</form>
		<a class="button" href="<?php echo esc_url( View::url( 'msp-logs', array( 'run_id' => $run->id ) ) ); ?>"><?php esc_html_e( 'View logs', 'micro-sync-pro' ); ?></a>
	</div>
</div>
<?php
View::render( 'partial-items', compact( 'run', 'items', 'items_total', 'items_page', 'filters', 'breakdown' ) + array( 'page_slug' => 'msp-history' ) );
View::footer();
