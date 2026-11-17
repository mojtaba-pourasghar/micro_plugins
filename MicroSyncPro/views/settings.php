<?php
/**
 * Settings.
 *
 * @package MicroStudio\MicroSyncPro
 *
 * @var array<string,mixed> $settings
 * @var \WP_Term[]          $categories
 */

use MicroStudio\MicroSyncPro\Admin\View;

defined( 'ABSPATH' ) || exit;

View::header( 'msp-settings', __( 'Settings', 'micro-sync-pro' ) );
$s     = $settings;
$rules = $s['category_rules'];
$num   = static function ( $name, $value, $min, $max, $step = '1', $extra = '' ) {
	printf(
		'<input type="number" id="msp-s-%1$s" name="settings[%1$s]" value="%2$s" min="%3$s" max="%4$s" step="%5$s" class="small-text msp-ltr" dir="ltr" %6$s>',
		esc_attr( $name ),
		esc_attr( (string) $value ),
		esc_attr( (string) $min ),
		esc_attr( (string) $max ),
		esc_attr( $step ),
		$extra // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute strings.
	);
};
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="msp_save_settings">
	<?php wp_nonce_field( 'msp_save_settings' ); ?>

	<div class="msp-panel">
		<h2><?php esc_html_e( 'Pricing rules', 'micro-sync-pro' ); ?></h2>
		<p class="msp-muted"><?php esc_html_e( 'Final price = supplier price × source multiplier × (1 + margin %), then rounded and limited to the min/max range.', 'micro-sync-pro' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="msp-s-margin_percent"><?php esc_html_e( 'Profit margin (%)', 'micro-sync-pro' ); ?></label></th>
				<td><?php $num( 'margin_percent', $s['margin_percent'], -99, 10000, '0.01' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="msp-s-min_price"><?php esc_html_e( 'Minimum price', 'micro-sync-pro' ); ?></label></th>
				<td><input type="text" inputmode="decimal" id="msp-s-min_price" name="settings[min_price]" value="<?php echo esc_attr( (string) $s['min_price'] ); ?>" class="regular-text msp-ltr" dir="ltr" placeholder="<?php esc_attr_e( 'No limit', 'micro-sync-pro' ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="msp-s-max_price"><?php esc_html_e( 'Maximum price', 'micro-sync-pro' ); ?></label></th>
				<td><input type="text" inputmode="decimal" id="msp-s-max_price" name="settings[max_price]" value="<?php echo esc_attr( (string) $s['max_price'] ); ?>" class="regular-text msp-ltr" dir="ltr" placeholder="<?php esc_attr_e( 'No limit', 'micro-sync-pro' ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="msp-s-out"><?php esc_html_e( 'When a price is out of range', 'micro-sync-pro' ); ?></label></th>
				<td>
					<select id="msp-s-out" name="settings[out_of_range]">
						<option value="clamp" <?php selected( $s['out_of_range'], 'clamp' ); ?>><?php esc_html_e( 'Use the nearest limit', 'micro-sync-pro' ); ?></option>
						<option value="skip" <?php selected( $s['out_of_range'], 'skip' ); ?>><?php esc_html_e( 'Do not update the price', 'micro-sync-pro' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="msp-s-step"><?php esc_html_e( 'Rounding', 'micro-sync-pro' ); ?></label></th>
				<td>
					<select id="msp-s-step" name="settings[rounding_step]">
						<?php
						$steps = array(
							0     => __( 'No rounding (store decimals)', 'micro-sync-pro' ),
							1     => '1',
							10    => '10',
							100   => '100',
							1000  => '1,000',
							10000 => '10,000',
						);
						foreach ( $steps as $k => $label ) :
							?>
							<option value="<?php echo esc_attr( (string) $k ); ?>" <?php selected( (int) $s['rounding_step'], $k ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<label for="msp-s-mode" class="screen-reader-text"><?php esc_html_e( 'Rounding direction', 'micro-sync-pro' ); ?></label>
					<select id="msp-s-mode" name="settings[rounding_mode]">
						<option value="nearest" <?php selected( $s['rounding_mode'], 'nearest' ); ?>><?php esc_html_e( 'Nearest', 'micro-sync-pro' ); ?></option>
						<option value="up" <?php selected( $s['rounding_mode'], 'up' ); ?>><?php esc_html_e( 'Up', 'micro-sync-pro' ); ?></option>
						<option value="down" <?php selected( $s['rounding_mode'], 'down' ); ?>><?php esc_html_e( 'Down', 'micro-sync-pro' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Options', 'micro-sync-pro' ); ?></th>
				<td>
					<fieldset>
						<label><input type="checkbox" name="settings[apply_pricing_to_sale]" value="1" <?php checked( $s['apply_pricing_to_sale'] ); ?>> <?php esc_html_e( 'Apply margin and limits to sale prices too', 'micro-sync-pro' ); ?></label><br>
						<label><input type="checkbox" name="settings[allow_zero_price]" value="1" <?php checked( $s['allow_zero_price'] ); ?>> <?php esc_html_e( 'Accept zero prices from the source', 'micro-sync-pro' ); ?></label><br>
						<label><input type="checkbox" name="settings[allow_negative_stock]" value="1" <?php checked( $s['allow_negative_stock'] ); ?>> <?php esc_html_e( 'Accept negative stock quantities', 'micro-sync-pro' ); ?></label>
					</fieldset>
				</td>
			</tr>
		</table>
	</div>

	<div class="msp-panel">
		<h2><?php esc_html_e( 'Category rules', 'micro-sync-pro' ); ?></h2>
		<p class="msp-muted"><?php esc_html_e( 'Override the margin and limits per product category. Rules are checked from top to bottom; the first rule matching a product (or its parent category, when enabled) is used. Variations use their parent product’s categories. Empty fields fall back to the global values.', 'micro-sync-pro' ); ?></p>
		<?php if ( empty( $categories ) ) : ?>
			<p class="msp-muted"><?php esc_html_e( 'No product categories exist yet.', 'micro-sync-pro' ); ?></p>
		<?php else : ?>
		<div class="msp-table-wrap">
		<table class="widefat msp-table msp-rules" data-msp-rules>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Category', 'micro-sync-pro' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Margin (%)', 'micro-sync-pro' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Min price', 'micro-sync-pro' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Max price', 'micro-sync-pro' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Include subcategories', 'micro-sync-pro' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Remove', 'micro-sync-pro' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
			<?php
			$rows   = $rules;
			$rows[] = array(
				'term_id'          => 0,
				'margin_percent'   => '',
				'min_price'        => '',
				'max_price'        => '',
				'include_children' => true,
			);
			foreach ( $rows as $i => $rule ) :
				$is_template = $i === count( $rows ) - 1;
				?>
				<tr <?php echo $is_template ? 'data-msp-rule-template' : ''; ?>>
					<td>
						<label class="screen-reader-text" for="msp-r-term-<?php echo esc_attr( (string) $i ); ?>"><?php esc_html_e( 'Category', 'micro-sync-pro' ); ?></label>
						<select id="msp-r-term-<?php echo esc_attr( (string) $i ); ?>" name="settings[category_rules][<?php echo esc_attr( (string) $i ); ?>][term_id]">
							<option value="0"><?php esc_html_e( '— Select —', 'micro-sync-pro' ); ?></option>
							<?php foreach ( $categories as $cat ) : ?>
								<option value="<?php echo esc_attr( (string) $cat->term_id ); ?>" <?php selected( (int) $rule['term_id'], (int) $cat->term_id ); ?>><?php echo esc_html( $cat->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
					<td><input type="text" inputmode="decimal" aria-label="<?php esc_attr_e( 'Margin (%)', 'micro-sync-pro' ); ?>" name="settings[category_rules][<?php echo esc_attr( (string) $i ); ?>][margin_percent]" value="<?php echo esc_attr( (string) $rule['margin_percent'] ); ?>" class="small-text msp-ltr" dir="ltr"></td>
					<td><input type="text" inputmode="decimal" aria-label="<?php esc_attr_e( 'Min price', 'micro-sync-pro' ); ?>" name="settings[category_rules][<?php echo esc_attr( (string) $i ); ?>][min_price]" value="<?php echo esc_attr( (string) $rule['min_price'] ); ?>" class="regular-text msp-ltr" dir="ltr"></td>
					<td><input type="text" inputmode="decimal" aria-label="<?php esc_attr_e( 'Max price', 'micro-sync-pro' ); ?>" name="settings[category_rules][<?php echo esc_attr( (string) $i ); ?>][max_price]" value="<?php echo esc_attr( (string) $rule['max_price'] ); ?>" class="regular-text msp-ltr" dir="ltr"></td>
					<td><input type="checkbox" aria-label="<?php esc_attr_e( 'Include subcategories', 'micro-sync-pro' ); ?>" name="settings[category_rules][<?php echo esc_attr( (string) $i ); ?>][include_children]" value="1" <?php checked( ! empty( $rule['include_children'] ) ); ?>></td>
					<td><button type="button" class="button-link button-link-delete" data-msp-remove-rule><?php esc_html_e( 'Remove', 'micro-sync-pro' ); ?></button></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<p><button type="button" class="button" data-msp-add-rule hidden><?php esc_html_e( 'Add rule', 'micro-sync-pro' ); ?></button></p>
		<?php endif; ?>
	</div>

	<div class="msp-panel">
		<h2><?php esc_html_e( 'Processing', 'micro-sync-pro' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="msp-s-batch_size"><?php esc_html_e( 'Batch size', 'micro-sync-pro' ); ?></label></th>
				<td><?php $num( 'batch_size', $s['batch_size'], 1, 500 ); ?> <span class="description"><?php esc_html_e( 'Records per batch. Lower it on slow hosts.', 'micro-sync-pro' ); ?></span></td>
			</tr>
			<tr>
				<th scope="row"><label for="msp-s-time_limit"><?php esc_html_e( 'Time per background slice (seconds)', 'micro-sync-pro' ); ?></label></th>
				<td><?php $num( 'time_limit', $s['time_limit'], 5, 120 ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="msp-s-max_upload_mb"><?php esc_html_e( 'Maximum upload size (MB)', 'micro-sync-pro' ); ?></label></th>
				<td><?php $num( 'max_upload_mb', $s['max_upload_mb'], 1, 512 ); ?>
				<span class="description">
				<?php
				/* translators: %s: server limit. */
				echo esc_html( sprintf( __( 'Server limit: %s', 'micro-sync-pro' ), size_format( wp_max_upload_size() ) ) );
				?>
				</span></td>
			</tr>
			<tr>
				<th scope="row"><label for="msp-s-max_remote_mb"><?php esc_html_e( 'Maximum remote download size (MB)', 'micro-sync-pro' ); ?></label></th>
				<td><?php $num( 'max_remote_mb', $s['max_remote_mb'], 1, 512 ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="msp-s-http_timeout"><?php esc_html_e( 'Remote request timeout (seconds)', 'micro-sync-pro' ); ?></label></th>
				<td><?php $num( 'http_timeout', $s['http_timeout'], 5, 120 ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="msp-s-stale_minutes"><?php esc_html_e( 'Consider a run stalled after (minutes)', 'micro-sync-pro' ); ?></label></th>
				<td><?php $num( 'stale_minutes', $s['stale_minutes'], 5, 1440 ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="msp-s-max_resume_attempts"><?php esc_html_e( 'Automatic resume attempts', 'micro-sync-pro' ); ?></label></th>
				<td><?php $num( 'max_resume_attempts', $s['max_resume_attempts'], 0, 10 ); ?> <span class="description"><?php esc_html_e( 'After this many automatic resumes a stalled run is marked as interrupted.', 'micro-sync-pro' ); ?></span></td>
			</tr>
		</table>
	</div>

	<div class="msp-panel">
		<h2><?php esc_html_e( 'Retention and data', 'micro-sync-pro' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="msp-s-log_retention_days"><?php esc_html_e( 'Keep logs for (days)', 'micro-sync-pro' ); ?></label></th>
				<td><?php $num( 'log_retention_days', $s['log_retention_days'], 1, 3650 ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="msp-s-history_retention_days"><?php esc_html_e( 'Keep run history for (days)', 'micro-sync-pro' ); ?></label></th>
				<td><?php $num( 'history_retention_days', $s['history_retention_days'], 1, 3650 ); ?> <span class="description"><?php esc_html_e( 'Runs older than this are deleted and can no longer be rolled back.', 'micro-sync-pro' ); ?></span></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Uninstall', 'micro-sync-pro' ); ?></th>
				<td><label><input type="checkbox" name="settings[delete_data_on_uninstall]" value="1" <?php checked( $s['delete_data_on_uninstall'] ); ?>> <?php esc_html_e( 'Delete all plugin data (tables, settings, uploaded files) when the plugin is deleted', 'micro-sync-pro' ); ?></label></td>
			</tr>
		</table>
	</div>

	<?php submit_button( __( 'Save settings', 'micro-sync-pro' ) ); ?>
</form>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="msp-panel" data-msp-confirm="<?php esc_attr_e( 'Delete logs and runs older than the retention periods now?', 'micro-sync-pro' ); ?>">
	<h2><?php esc_html_e( 'Maintenance', 'micro-sync-pro' ); ?></h2>
	<input type="hidden" name="action" value="msp_cleanup_now">
	<?php wp_nonce_field( 'msp_cleanup_now' ); ?>
	<p><?php esc_html_e( 'Cleanup runs automatically once a day.', 'micro-sync-pro' ); ?></p>
	<button type="submit" class="button"><?php esc_html_e( 'Run cleanup now', 'micro-sync-pro' ); ?></button>
</form>
<?php
View::footer();
