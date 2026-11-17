<?php
/**
 * Live progress panel.
 *
 * @package MicroStudio\MicroSyncPro
 *
 * @var \MicroStudio\MicroSyncPro\Sync\Run $run
 * @var bool                               $compact
 * @var string                             $name
 */

use MicroStudio\MicroSyncPro\Admin\View;

defined( 'ABSPATH' ) || exit;

$compact = ! empty( $compact );
?>
<div class="msp-progress" data-msp-run="<?php echo esc_attr( (string) $run->id ); ?>" data-msp-active="<?php echo $run->is_active() ? '1' : '0'; ?>" data-msp-reload="<?php echo $compact ? '0' : '1'; ?>">
	<div class="msp-progress__head">
		<strong>
			<?php
			/* translators: 1: run id, 2: mode, 3: source name. */
			echo esc_html( sprintf( __( 'Run #%1$d — %2$s — %3$s', 'micro-sync-pro' ), $run->id, View::mode_label( $run->mode ), '' !== (string) $name ? $name : __( '(deleted source)', 'micro-sync-pro' ) ) );
			?>
		</strong>
		<span class="msp-progress__status" data-msp-field="status"><?php echo View::badge( $run->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge(). ?></span>
	</div>
	<div class="msp-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $run->percent() ); ?>" aria-label="<?php esc_attr_e( 'Synchronization progress', 'micro-sync-pro' ); ?>">
		<span class="msp-bar__fill" style="width: <?php echo esc_attr( (string) $run->percent() ); ?>%"></span>
	</div>
	<p class="msp-progress__meta" aria-live="polite">
		<span data-msp-field="phase"></span>
		<span data-msp-field="counts">
		<?php
		/* translators: 1: processed, 2: total. */
		echo esc_html( sprintf( __( '%1$s of %2$s records', 'micro-sync-pro' ), number_format_i18n( $run->processed ), number_format_i18n( $run->total ) ) );
		?>
		</span>
	</p>
	<?php if ( ! $compact ) : ?>
	<ul class="msp-counters">
		<li><span><?php echo esc_html( 'dry_run' === $run->mode ? __( 'Will update', 'micro-sync-pro' ) : ( 'rollback' === $run->mode ? __( 'Reverted', 'micro-sync-pro' ) : __( 'Updated', 'micro-sync-pro' ) ) ); ?></span> <strong data-msp-field="updated"><?php echo esc_html( number_format_i18n( $run->updated ) ); ?></strong></li>
		<li><span><?php esc_html_e( 'Unchanged', 'micro-sync-pro' ); ?></span> <strong data-msp-field="unchanged"><?php echo esc_html( number_format_i18n( $run->unchanged ) ); ?></strong></li>
		<li><span><?php esc_html_e( 'Skipped', 'micro-sync-pro' ); ?></span> <strong data-msp-field="skipped"><?php echo esc_html( number_format_i18n( $run->skipped ) ); ?></strong></li>
		<li><span><?php esc_html_e( 'Failed', 'micro-sync-pro' ); ?></span> <strong data-msp-field="failed"><?php echo esc_html( number_format_i18n( $run->failed ) ); ?></strong></li>
	</ul>
	<?php endif; ?>
	<?php if ( $run->is_active() ) : ?>
		<p class="msp-progress__actions">
			<button type="button" class="button" data-msp-cancel="<?php echo esc_attr( (string) $run->id ); ?>"><?php esc_html_e( 'Cancel', 'micro-sync-pro' ); ?></button>
			<a class="button-link" href="<?php echo esc_url( View::url( 'msp-history', array( 'run' => $run->id ) ) ); ?>"><?php esc_html_e( 'Details', 'micro-sync-pro' ); ?></a>
		</p>
	<?php endif; ?>
	<p class="msp-progress__error notice notice-error inline" data-msp-field="error" hidden></p>
</div>
