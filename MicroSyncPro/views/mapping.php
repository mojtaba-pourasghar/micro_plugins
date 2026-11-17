<?php
/**
 * Field mapping screen.
 *
 * @package MicroStudio\MicroSyncPro
 *
 * @var \MicroStudio\MicroSyncPro\Sources\Source[]   $sources
 * @var \MicroStudio\MicroSyncPro\Sources\Source|null $source
 * @var array<string,array>                         $mapping
 * @var bool                                        $suggested
 * @var array<int,array<string,string>>             $sample
 * @var bool                                        $complete
 */

use MicroStudio\MicroSyncPro\Admin\View;
use MicroStudio\MicroSyncPro\Mapping\FieldMapper;

defined( 'ABSPATH' ) || exit;

View::header( 'msp-mapping', __( 'Field Mapping', 'micro-sync-pro' ) );

if ( empty( $sources ) || ! $source ) {
	View::empty_state( __( 'No data source', 'micro-sync-pro' ), __( 'Add a data source first; its columns are detected automatically.', 'micro-sync-pro' ), View::url( 'msp-sources', array( 'action' => 'new' ) ), __( 'Add data source', 'micro-sync-pro' ) );
	View::footer();
	return;
}
$labels = FieldMapper::labels();
?>
<form method="get" class="msp-filters">
	<input type="hidden" name="page" value="msp-mapping">
	<label for="msp-map-source"><?php esc_html_e( 'Data source', 'micro-sync-pro' ); ?></label>
	<select id="msp-map-source" name="source" data-msp-autosubmit>
		<?php foreach ( $sources as $s ) : ?>
			<option value="<?php echo esc_attr( (string) $s->id ); ?>" <?php selected( $source->id, $s->id ); ?>><?php echo esc_html( $s->name ); ?></option>
		<?php endforeach; ?>
	</select>
	<noscript><button type="submit" class="button"><?php esc_html_e( 'Select', 'micro-sync-pro' ); ?></button></noscript>
</form>

<div class="msp-panel">
	<h2><?php esc_html_e( 'Detected columns', 'micro-sync-pro' ); ?></h2>
	<?php if ( empty( $source->columns ) ) : ?>
		<p class="msp-muted"><?php esc_html_e( 'No columns detected yet.', 'micro-sync-pro' ); ?></p>
	<?php else : ?>
		<p class="msp-columns">
			<?php foreach ( $source->columns as $col ) : ?>
				<code><bdi><?php echo esc_html( $col ); ?></bdi></code>
			<?php endforeach; ?>
		</p>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="msp_detect_columns">
		<input type="hidden" name="source_id" value="<?php echo esc_attr( (string) $source->id ); ?>">
		<?php wp_nonce_field( 'msp_detect_columns' ); ?>
		<button type="submit" class="button" data-msp-busy="<?php esc_attr_e( 'Reading source…', 'micro-sync-pro' ); ?>"><span class="dashicons dashicons-update" aria-hidden="true"></span> <?php esc_html_e( 'Re-read columns from source', 'micro-sync-pro' ); ?></button>
	</form>
</div>

<?php if ( ! empty( $source->columns ) ) : ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="msp-panel">
	<h2><?php esc_html_e( 'Map columns to WooCommerce fields', 'micro-sync-pro' ); ?></h2>
	<?php if ( $suggested ) : ?>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'A mapping was suggested from the column names. Review it and save.', 'micro-sync-pro' ); ?></p></div>
	<?php elseif ( ! $complete ) : ?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'The mapping is incomplete: map SKU and enable at least one field.', 'micro-sync-pro' ); ?></p></div>
	<?php endif; ?>
	<input type="hidden" name="action" value="msp_save_mapping">
	<input type="hidden" name="source_id" value="<?php echo esc_attr( (string) $source->id ); ?>">
	<?php wp_nonce_field( 'msp_save_mapping' ); ?>
	<div class="msp-table-wrap">
	<table class="widefat striped msp-table msp-mapping-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Synchronize', 'micro-sync-pro' ); ?></th>
				<th scope="col"><?php esc_html_e( 'WooCommerce field', 'micro-sync-pro' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Source column', 'micro-sync-pro' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Example value', 'micro-sync-pro' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( FieldMapper::FIELDS as $field ) : ?>
			<?php
			$m       = $mapping[ $field ] ?? array( 'column' => '', 'enabled' => false );
			$is_sku  = FieldMapper::SKU === $field;
			$example = ( '' !== $m['column'] && isset( $sample[0][ $m['column'] ] ) ) ? $sample[0][ $m['column'] ] : '';
			$sel_id  = 'msp-col-' . $field;
			?>
			<tr>
				<td>
					<?php if ( $is_sku ) : ?>
						<span class="dashicons dashicons-lock" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'Always used for matching', 'micro-sync-pro' ); ?></span>
					<?php else : ?>
						<input type="checkbox" id="msp-en-<?php echo esc_attr( $field ); ?>" name="mapping[<?php echo esc_attr( $field ); ?>][enabled]" value="1" <?php checked( ! empty( $m['enabled'] ) ); ?>>
						<label class="screen-reader-text" for="msp-en-<?php echo esc_attr( $field ); ?>">
						<?php
						/* translators: %s: field label. */
						echo esc_html( sprintf( __( 'Synchronize %s', 'micro-sync-pro' ), $labels[ $field ] ) );
						?>
						</label>
					<?php endif; ?>
				</td>
				<td><label for="<?php echo esc_attr( $sel_id ); ?>"><strong><?php echo esc_html( $labels[ $field ] ); ?></strong></label></td>
				<td>
					<select id="<?php echo esc_attr( $sel_id ); ?>" name="mapping[<?php echo esc_attr( $field ); ?>][column]" <?php echo $is_sku ? 'required' : ''; ?>>
						<option value=""><?php esc_html_e( '— Not mapped —', 'micro-sync-pro' ); ?></option>
						<?php foreach ( $source->columns as $col ) : ?>
							<option value="<?php echo esc_attr( $col ); ?>" <?php selected( $m['column'], $col ); ?>><?php echo esc_html( $col ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
				<td><bdi class="msp-mono"><?php echo esc_html( $example ); ?></bdi></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>
	<p class="description"><?php esc_html_e( 'Only enabled fields are ever written. Products are matched by exact SKU (simple products and variations); unmatched rows are reported and no products are created.', 'micro-sync-pro' ); ?></p>
	<p class="submit">
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Save mapping', 'micro-sync-pro' ); ?></button>
		<?php if ( $complete ) : ?>
			<a class="button" href="<?php echo esc_url( View::url( 'msp-sync', array( 'source' => $source->id ) ) ); ?>"><?php esc_html_e( 'Go to synchronization', 'micro-sync-pro' ); ?></a>
		<?php endif; ?>
	</p>
</form>

	<?php if ( ! empty( $sample ) ) : ?>
	<div class="msp-panel">
		<h2><?php esc_html_e( 'Source preview (first rows)', 'micro-sync-pro' ); ?></h2>
		<div class="msp-table-wrap">
		<table class="widefat striped msp-table">
			<thead><tr>
				<?php foreach ( $source->columns as $col ) : ?>
					<th scope="col"><bdi><?php echo esc_html( $col ); ?></bdi></th>
				<?php endforeach; ?>
			</tr></thead>
			<tbody>
			<?php foreach ( $sample as $row ) : ?>
				<tr>
				<?php foreach ( $source->columns as $col ) : ?>
					<td><bdi><?php echo esc_html( (string) ( $row[ $col ] ?? '' ) ); ?></bdi></td>
				<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
	</div>
	<?php endif; ?>
<?php endif; ?>
<?php
View::footer();
