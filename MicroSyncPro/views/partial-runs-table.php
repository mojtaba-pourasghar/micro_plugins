<?php
/**
 * Runs table.
 *
 * @package MicroStudio\MicroSyncPro
 *
 * @var \MicroStudio\MicroSyncPro\Sync\Run[] $runs
 * @var array<int,string>                    $names
 * @var bool                                 $compact
 */

use MicroStudio\MicroSyncPro\Admin\View;

defined( 'ABSPATH' ) || exit;

$compact = ! empty( $compact );
?>
<div class="msp-table-wrap">
<table class="widefat striped msp-table">
	<thead>
		<tr>
			<th scope="col"><?php esc_html_e( 'Run', 'micro-sync-pro' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Source', 'micro-sync-pro' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Mode', 'micro-sync-pro' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Status', 'micro-sync-pro' ); ?></th>
			<?php if ( ! $compact ) : ?>
				<th scope="col"><?php esc_html_e( 'Trigger', 'micro-sync-pro' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Started', 'micro-sync-pro' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Finished', 'micro-sync-pro' ); ?></th>
				<th scope="col" class="num"><?php esc_html_e( 'Total', 'micro-sync-pro' ); ?></th>
			<?php endif; ?>
			<th scope="col" class="num"><?php esc_html_e( 'Updated', 'micro-sync-pro' ); ?></th>
			<?php if ( ! $compact ) : ?>
				<th scope="col" class="num"><?php esc_html_e( 'Unchanged', 'micro-sync-pro' ); ?></th>
			<?php endif; ?>
			<th scope="col" class="num"><?php esc_html_e( 'Skipped', 'micro-sync-pro' ); ?></th>
			<th scope="col" class="num"><?php esc_html_e( 'Failed', 'micro-sync-pro' ); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php foreach ( $runs as $run ) : ?>
		<tr>
			<td>
				<a href="<?php echo esc_url( View::url( 'msp-history', array( 'run' => $run->id ) ) ); ?>">#<?php echo esc_html( (string) $run->id ); ?></a>
				<?php if ( ! $compact ) : ?>
					<br><code class="msp-uuid" title="<?php esc_attr_e( 'Execution ID', 'micro-sync-pro' ); ?>"><?php echo esc_html( substr( $run->uuid, 0, 8 ) ); ?></code>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( $names[ $run->source_id ] ?? ( $run->snapshot['source_name'] ?? __( '(deleted source)', 'micro-sync-pro' ) ) ); ?></td>
			<td><?php echo esc_html( View::mode_label( $run->mode ) ); ?></td>
			<td><?php echo View::badge( $run->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
			<?php if ( ! $compact ) : ?>
				<td><?php echo esc_html( View::trigger_label( $run->trigger ) ); ?></td>
				<td><?php echo esc_html( View::date( $run->started_at ? $run->started_at : $run->created_at ) ); ?></td>
				<td><?php echo esc_html( View::date( $run->finished_at ) ); ?></td>
				<td class="num"><?php echo esc_html( number_format_i18n( $run->total ) ); ?></td>
			<?php endif; ?>
			<td class="num"><?php echo esc_html( number_format_i18n( $run->updated ) ); ?></td>
			<?php if ( ! $compact ) : ?>
				<td class="num"><?php echo esc_html( number_format_i18n( $run->unchanged ) ); ?></td>
			<?php endif; ?>
			<td class="num"><?php echo esc_html( number_format_i18n( $run->skipped ) ); ?></td>
			<td class="num"><?php echo esc_html( number_format_i18n( $run->failed ) ); ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
</div>
