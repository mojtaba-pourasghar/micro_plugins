<?php
/**
 * Logs.
 *
 * @package MicroStudio\MicroSyncPro
 *
 * @var array<int,object>   $logs
 * @var int                 $total
 * @var int                 $page
 * @var array<string,mixed> $filters
 * @var int                 $retention
 */

use MicroStudio\MicroSyncPro\Admin\View;

defined( 'ABSPATH' ) || exit;

View::header( 'msp-logs', __( 'Logs', 'micro-sync-pro' ) );
$levels = array(
	'info'    => __( 'Info', 'micro-sync-pro' ),
	'warning' => __( 'Warning', 'micro-sync-pro' ),
	'error'   => __( 'Error', 'micro-sync-pro' ),
	'debug'   => __( 'Debug', 'micro-sync-pro' ),
);
?>
<p class="msp-muted">
<?php
/* translators: %d: number of days. */
echo esc_html( sprintf( __( 'Logs are kept for %d days (see Settings). Credentials and full URLs are never logged.', 'micro-sync-pro' ), $retention ) );
?>
</p>
<form method="get" class="msp-filters">
	<input type="hidden" name="page" value="msp-logs">
	<label for="msp-l-level"><?php esc_html_e( 'Level', 'micro-sync-pro' ); ?></label>
	<select id="msp-l-level" name="level">
		<option value=""><?php esc_html_e( 'All', 'micro-sync-pro' ); ?></option>
		<?php foreach ( $levels as $k => $label ) : ?>
			<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $filters['level'], $k ); ?>><?php echo esc_html( $label ); ?></option>
		<?php endforeach; ?>
	</select>
	<label for="msp-l-run"><?php esc_html_e( 'Run ID', 'micro-sync-pro' ); ?></label>
	<input type="number" min="0" id="msp-l-run" name="run_id" class="small-text" value="<?php echo esc_attr( $filters['run_id'] ? (string) $filters['run_id'] : '' ); ?>">
	<label for="msp-l-s" class="screen-reader-text"><?php esc_html_e( 'Search logs', 'micro-sync-pro' ); ?></label>
	<input type="search" id="msp-l-s" name="s" value="<?php echo esc_attr( (string) $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search…', 'micro-sync-pro' ); ?>">
	<button type="submit" class="button"><?php esc_html_e( 'Filter', 'micro-sync-pro' ); ?></button>
</form>
<?php if ( empty( $logs ) ) : ?>
	<?php View::empty_state( __( 'No log entries', 'micro-sync-pro' ), __( 'Synchronization events, warnings and errors will appear here.', 'micro-sync-pro' ) ); ?>
<?php else : ?>
	<div class="msp-table-wrap">
	<table class="widefat striped msp-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Date', 'micro-sync-pro' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Level', 'micro-sync-pro' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Run', 'micro-sync-pro' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Message', 'micro-sync-pro' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Context', 'micro-sync-pro' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $logs as $log ) : ?>
			<tr>
				<td class="msp-nowrap"><?php echo esc_html( View::date( (string) $log->created_at ) ); ?></td>
				<td><span class="msp-badge msp-badge--<?php echo esc_attr( sanitize_html_class( (string) $log->level ) ); ?>"><?php echo esc_html( $levels[ $log->level ] ?? $log->level ); ?></span></td>
				<td>
					<?php if ( $log->run_id ) : ?>
						<a href="<?php echo esc_url( View::url( 'msp-history', array( 'run' => (int) $log->run_id ) ) ); ?>">#<?php echo esc_html( (string) $log->run_id ); ?></a>
					<?php else : ?>
						—
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( (string) $log->message ); ?></td>
				<td>
					<?php if ( ! empty( $log->context ) ) : ?>
						<code class="msp-context" dir="ltr"><?php echo esc_html( (string) $log->context ); ?></code>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>
	<?php View::pagination( $total, $page, 50 ); ?>
<?php endif; ?>
<?php
View::footer();
