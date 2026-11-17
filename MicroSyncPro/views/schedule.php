<?php
/**
 * Schedule screen.
 *
 * @package MicroStudio\MicroSyncPro
 *
 * @var \MicroStudio\MicroSyncPro\Sources\Source[]     $sources
 * @var array<int,int>                                $next
 * @var \MicroStudio\MicroSyncPro\Mapping\FieldMapper $mapper
 * @var bool                                          $as
 */

use MicroStudio\MicroSyncPro\Admin\View;
use MicroStudio\MicroSyncPro\Scheduling\Scheduler;

defined( 'ABSPATH' ) || exit;

View::header( 'msp-schedule', __( 'Schedule', 'micro-sync-pro' ) );
?>
<div class="msp-panel">
	<p>
		<?php esc_html_e( 'Scheduled synchronizations apply changes directly (no preview) using the current mapping and settings. A source is never synchronized twice at the same time; if a run is still active, the scheduled run is skipped and logged.', 'micro-sync-pro' ); ?>
	</p>
	<p class="msp-muted">
		<?php
		echo esc_html(
			$as
				? __( 'Runner: WooCommerce Action Scheduler.', 'micro-sync-pro' )
				: __( 'Runner: WP-Cron. On low-traffic sites, configure a real server cron job that calls wp-cron.php for reliable timing.', 'micro-sync-pro' )
		);
		?>
	</p>
</div>
<?php if ( empty( $sources ) ) : ?>
	<?php View::empty_state( __( 'No data source', 'micro-sync-pro' ), __( 'Add a data source before scheduling.', 'micro-sync-pro' ), View::url( 'msp-sources', array( 'action' => 'new' ) ), __( 'Add data source', 'micro-sync-pro' ) ); ?>
<?php else : ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="msp_save_schedule">
	<?php wp_nonce_field( 'msp_save_schedule' ); ?>
	<div class="msp-table-wrap">
	<table class="widefat striped msp-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Data source', 'micro-sync-pro' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Frequency', 'micro-sync-pro' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Next run', 'micro-sync-pro' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'micro-sync-pro' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $sources as $s ) : ?>
			<?php $ready = 'active' === $s->status && $mapper->is_complete( $s->mapping ); ?>
			<tr>
				<td><label for="msp-sched-<?php echo esc_attr( (string) $s->id ); ?>"><strong><?php echo esc_html( $s->name ); ?></strong></label></td>
				<td>
					<select id="msp-sched-<?php echo esc_attr( (string) $s->id ); ?>" name="schedule[<?php echo esc_attr( (string) $s->id ); ?>]">
						<?php foreach ( Scheduler::labels() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s->schedule, $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
				<td><?php echo esc_html( ! empty( $next[ $s->id ] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $next[ $s->id ] ) : '—' ); ?></td>
				<td>
					<?php if ( $ready ) : ?>
						<span class="dashicons dashicons-yes-alt msp-ok" aria-hidden="true"></span> <?php esc_html_e( 'Ready', 'micro-sync-pro' ); ?>
					<?php elseif ( 'active' !== $s->status ) : ?>
						<span class="dashicons dashicons-warning msp-warn" aria-hidden="true"></span> <?php esc_html_e( 'Source disabled', 'micro-sync-pro' ); ?>
					<?php else : ?>
						<span class="dashicons dashicons-warning msp-warn" aria-hidden="true"></span> <?php esc_html_e( 'Mapping incomplete', 'micro-sync-pro' ); ?>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>
	<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save schedules', 'micro-sync-pro' ); ?></button></p>
</form>
<?php endif; ?>
<?php
View::footer();
