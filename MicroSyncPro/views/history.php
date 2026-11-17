<?php
/**
 * History list.
 *
 * @package MicroStudio\MicroSyncPro
 *
 * @var \MicroStudio\MicroSyncPro\Sync\Run[] $runs
 * @var int                                  $total
 * @var int                                  $page
 * @var array<string,mixed>                  $filters
 * @var array<int,string>                    $names
 */

use MicroStudio\MicroSyncPro\Admin\Pages;
use MicroStudio\MicroSyncPro\Admin\View;
use MicroStudio\MicroSyncPro\Sync\Run;

defined( 'ABSPATH' ) || exit;

View::header( 'msp-history', __( 'History', 'micro-sync-pro' ) );
$statuses = array( Run::STATUS_QUEUED, Run::STATUS_RUNNING, Run::STATUS_COMPLETED, Run::STATUS_FAILED, Run::STATUS_CANCELLED, Run::STATUS_INTERRUPTED );
?>
<form method="get" class="msp-filters">
	<input type="hidden" name="page" value="msp-history">
	<label for="msp-h-source"><?php esc_html_e( 'Source', 'micro-sync-pro' ); ?></label>
	<select id="msp-h-source" name="source_id">
		<option value="0"><?php esc_html_e( 'All', 'micro-sync-pro' ); ?></option>
		<?php foreach ( $names as $id => $name ) : ?>
			<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( (int) $filters['source_id'], $id ); ?>><?php echo esc_html( $name ); ?></option>
		<?php endforeach; ?>
	</select>
	<label for="msp-h-mode"><?php esc_html_e( 'Mode', 'micro-sync-pro' ); ?></label>
	<select id="msp-h-mode" name="mode">
		<option value=""><?php esc_html_e( 'All', 'micro-sync-pro' ); ?></option>
		<?php foreach ( array( Run::MODE_DRY_RUN, Run::MODE_APPLY, Run::MODE_ROLLBACK ) as $m ) : ?>
			<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $filters['mode'], $m ); ?>><?php echo esc_html( View::mode_label( $m ) ); ?></option>
		<?php endforeach; ?>
	</select>
	<label for="msp-h-status"><?php esc_html_e( 'Status', 'micro-sync-pro' ); ?></label>
	<select id="msp-h-status" name="status">
		<option value=""><?php esc_html_e( 'All', 'micro-sync-pro' ); ?></option>
		<?php foreach ( $statuses as $st ) : ?>
			<option value="<?php echo esc_attr( $st ); ?>" <?php selected( $filters['status'], $st ); ?>><?php echo esc_html( View::status_labels()[ $st ] ); ?></option>
		<?php endforeach; ?>
	</select>
	<button type="submit" class="button"><?php esc_html_e( 'Filter', 'micro-sync-pro' ); ?></button>
</form>
<?php
if ( empty( $runs ) ) {
	View::empty_state( __( 'No runs found', 'micro-sync-pro' ), __( 'Every preview, synchronization and rollback is recorded here with its per-record results.', 'micro-sync-pro' ) );
} else {
	View::render( 'partial-runs-table', array( 'runs' => $runs, 'names' => $names, 'compact' => false ) );
	View::pagination( $total, $page, Pages::PER_PAGE );
}
View::footer();
