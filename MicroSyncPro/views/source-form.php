<?php
/**
 * Source add/edit form.
 *
 * @package MicroStudio\MicroSyncPro
 *
 * @var \MicroStudio\MicroSyncPro\Sources\Source|null $source
 * @var bool                                          $has_secret
 * @var int                                           $max_upload
 */

use MicroStudio\MicroSyncPro\Admin\View;
use MicroStudio\MicroSyncPro\Sources\SourceTypes;

defined( 'ABSPATH' ) || exit;

$is_edit = null !== $source;
$type    = $is_edit ? $source->type : SourceTypes::CSV_UPLOAD;
$opt     = static function ( $key, $default = '' ) use ( $source ) {
	return $source ? $source->opt( $key, $default ) : $default;
};
View::header( 'msp-sources', $is_edit ? __( 'Edit data source', 'micro-sync-pro' ) : __( 'Add data source', 'micro-sync-pro' ) );
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="msp-form" data-msp-source-form>
	<input type="hidden" name="action" value="msp_save_source">
	<input type="hidden" name="source_id" value="<?php echo esc_attr( $is_edit ? (string) $source->id : '0' ); ?>">
	<?php wp_nonce_field( 'msp_save_source' ); ?>

	<div class="msp-panel">
		<h2><?php esc_html_e( 'General', 'micro-sync-pro' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="msp-name"><?php esc_html_e( 'Name', 'micro-sync-pro' ); ?> <span class="msp-req" aria-hidden="true">*</span></label></th>
				<td><input type="text" id="msp-name" name="name" class="regular-text" required maxlength="190" value="<?php echo esc_attr( $is_edit ? $source->name : '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. Main supplier price list', 'micro-sync-pro' ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="msp-type"><?php esc_html_e( 'Source type', 'micro-sync-pro' ); ?></label></th>
				<td>
					<select id="msp-type" name="type" <?php disabled( $is_edit ); ?> data-msp-type>
						<?php foreach ( SourceTypes::labels() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $type, $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php if ( $is_edit ) : ?>
						<p class="description"><?php esc_html_e( 'The type cannot be changed after creation. Create a new source instead.', 'micro-sync-pro' ); ?></p>
					<?php endif; ?>
					<?php if ( ! SourceTypes::xlsx_supported() ) : ?>
						<p class="description"><?php esc_html_e( 'Excel (XLSX) files require the PHP zip and xmlreader extensions, which are not available on this server.', 'micro-sync-pro' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Status', 'micro-sync-pro' ); ?></th>
				<td>
					<label><input type="checkbox" name="enabled" value="1" <?php checked( ! $is_edit || 'active' === $source->status ); ?>> <?php esc_html_e( 'Enabled', 'micro-sync-pro' ); ?></label>
					<p class="description"><?php esc_html_e( 'Disabled sources cannot be synchronized manually or by schedule.', 'micro-sync-pro' ); ?></p>
				</td>
			</tr>
		</table>
	</div>

	<div class="msp-panel" data-msp-show-for="csv_upload xlsx_upload">
		<h2><?php esc_html_e( 'File', 'micro-sync-pro' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="msp-file"><?php esc_html_e( 'Upload file', 'micro-sync-pro' ); ?></label></th>
				<td>
					<input type="file" id="msp-file" name="source_file" accept=".csv,.txt,.xlsx" aria-describedby="msp-file-help">
					<p class="description" id="msp-file-help">
						<?php
						/* translators: %s: maximum size. */
						echo esc_html( sprintf( __( 'CSV (.csv) or Excel (.xlsx). Maximum size: %s. The first row must contain column names.', 'micro-sync-pro' ), size_format( $max_upload ) ) );
						?>
					</p>
					<?php if ( $is_edit && '' !== (string) $opt( 'original_name' ) ) : ?>
						<p>
						<?php
						/* translators: %s: file name. */
						echo esc_html( sprintf( __( 'Current file: %s (upload a new one to replace it).', 'micro-sync-pro' ), (string) $opt( 'original_name' ) ) );
						?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
	</div>

	<div class="msp-panel" data-msp-show-for="csv_url json_url">
		<h2><?php esc_html_e( 'Remote address', 'micro-sync-pro' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="msp-url"><?php esc_html_e( 'URL', 'micro-sync-pro' ); ?></label></th>
				<td>
					<input type="url" id="msp-url" name="config[url]" class="large-text msp-ltr" dir="ltr" autocomplete="off" placeholder="https://supplier.example.com/prices.csv" aria-describedby="msp-url-help">
					<p class="description" id="msp-url-help">
						<?php esc_html_e( 'Only public http/https addresses are allowed. Private, local and reserved network addresses are blocked.', 'micro-sync-pro' ); ?>
						<?php if ( $is_edit && '' !== (string) $opt( 'url_display' ) ) : ?>
							<br>
							<?php
							/* translators: %s: masked URL. */
							echo esc_html( sprintf( __( 'Saved address: %s — leave empty to keep it.', 'micro-sync-pro' ), (string) $opt( 'url_display' ) ) );
							?>
						<?php endif; ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="msp-auth"><?php esc_html_e( 'Authentication', 'micro-sync-pro' ); ?></label></th>
				<td>
					<select id="msp-auth" name="config[auth_type]" data-msp-auth>
						<?php
						$auths = array(
							'none'   => __( 'None', 'micro-sync-pro' ),
							'bearer' => __( 'Bearer token', 'micro-sync-pro' ),
							'basic'  => __( 'Username and password (Basic)', 'micro-sync-pro' ),
							'header' => __( 'Custom header (API key)', 'micro-sync-pro' ),
						);
						foreach ( $auths as $k => $label ) :
							?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( (string) $opt( 'auth_type', 'none' ), $k ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr data-msp-auth-for="basic">
				<th scope="row"><label for="msp-auth-user"><?php esc_html_e( 'Username', 'micro-sync-pro' ); ?></label></th>
				<td><input type="text" id="msp-auth-user" name="config[auth_user]" class="regular-text msp-ltr" dir="ltr" autocomplete="off" value="<?php echo esc_attr( (string) $opt( 'auth_user' ) ); ?>"></td>
			</tr>
			<tr data-msp-auth-for="header">
				<th scope="row"><label for="msp-header-name"><?php esc_html_e( 'Header name', 'micro-sync-pro' ); ?></label></th>
				<td><input type="text" id="msp-header-name" name="config[header_name]" class="regular-text msp-ltr" dir="ltr" autocomplete="off" placeholder="X-API-Key" value="<?php echo esc_attr( (string) $opt( 'header_name' ) ); ?>"></td>
			</tr>
			<tr data-msp-auth-for="bearer basic header">
				<th scope="row"><label for="msp-secret"><?php esc_html_e( 'Token / password', 'micro-sync-pro' ); ?></label></th>
				<td>
					<input type="password" id="msp-secret" name="config[auth_secret]" class="regular-text msp-ltr" dir="ltr" autocomplete="new-password" aria-describedby="msp-secret-help">
					<p class="description" id="msp-secret-help">
						<?php
						echo esc_html(
							$has_secret
								? __( 'A credential is stored (encrypted). Leave empty to keep it.', 'micro-sync-pro' )
								: __( 'Stored encrypted and never shown or logged.', 'micro-sync-pro' )
						);
						?>
					</p>
				</td>
			</tr>
			<tr data-msp-show-for="json_url">
				<th scope="row"><label for="msp-records-path"><?php esc_html_e( 'Records path (JSON)', 'micro-sync-pro' ); ?></label></th>
				<td>
					<input type="text" id="msp-records-path" name="config[records_path]" class="regular-text msp-ltr" dir="ltr" value="<?php echo esc_attr( (string) $opt( 'records_path' ) ); ?>" placeholder="data.items" aria-describedby="msp-records-help">
					<p class="description" id="msp-records-help"><?php esc_html_e( 'Dot path to the list of products in the JSON response. Leave empty when the response itself is a list.', 'micro-sync-pro' ); ?></p>
				</td>
			</tr>
		</table>
	</div>

	<div class="msp-panel">
		<h2><?php esc_html_e( 'Format and values', 'micro-sync-pro' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr data-msp-show-for="csv_upload csv_url">
				<th scope="row"><label for="msp-delimiter"><?php esc_html_e( 'CSV delimiter', 'micro-sync-pro' ); ?></label></th>
				<td>
					<select id="msp-delimiter" name="config[delimiter]">
						<?php
						$delims = array(
							'auto' => __( 'Detect automatically', 'micro-sync-pro' ),
							','    => __( 'Comma (,)', 'micro-sync-pro' ),
							';'    => __( 'Semicolon (;)', 'micro-sync-pro' ),
							'tab'  => __( 'Tab', 'micro-sync-pro' ),
							'|'    => __( 'Pipe (|)', 'micro-sync-pro' ),
						);
						foreach ( $delims as $k => $label ) :
							?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( (string) $opt( 'delimiter', 'auto' ), $k ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr data-msp-show-for="csv_upload csv_url">
				<th scope="row"><label for="msp-encoding"><?php esc_html_e( 'Text encoding', 'micro-sync-pro' ); ?></label></th>
				<td>
					<select id="msp-encoding" name="config[encoding]">
						<option value="AUTO" <?php selected( (string) $opt( 'encoding', 'AUTO' ), 'AUTO' ); ?>><?php esc_html_e( 'Detect automatically', 'micro-sync-pro' ); ?></option>
						<option value="UTF-8" <?php selected( (string) $opt( 'encoding', 'AUTO' ), 'UTF-8' ); ?>>UTF-8</option>
						<option value="WINDOWS-1256" <?php selected( (string) $opt( 'encoding', 'AUTO' ), 'WINDOWS-1256' ); ?>><?php esc_html_e( 'Windows-1256 (Persian/Arabic Excel)', 'micro-sync-pro' ); ?></option>
					</select>
				</td>
			</tr>
			<tr data-msp-show-for="xlsx_upload">
				<th scope="row"><label for="msp-sheet"><?php esc_html_e( 'Worksheet number', 'micro-sync-pro' ); ?></label></th>
				<td><input type="number" id="msp-sheet" name="config[sheet]" min="1" max="50" class="small-text" value="<?php echo esc_attr( (string) $opt( 'sheet', 1 ) ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="msp-decimal"><?php esc_html_e( 'Decimal separator', 'micro-sync-pro' ); ?></label></th>
				<td>
					<select id="msp-decimal" name="config[decimal_separator]" aria-describedby="msp-decimal-help">
						<option value="." <?php selected( (string) $opt( 'decimal_separator', '.' ), '.' ); ?>><?php esc_html_e( 'Dot — 1,250,000.50', 'micro-sync-pro' ); ?></option>
						<option value="," <?php selected( (string) $opt( 'decimal_separator', '.' ), ',' ); ?>><?php esc_html_e( 'Comma — 1.250.000,50', 'micro-sync-pro' ); ?></option>
					</select>
					<p class="description" id="msp-decimal-help"><?php esc_html_e( 'Persian digits (۰–۹) and separators are converted automatically.', 'micro-sync-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="msp-multiplier"><?php esc_html_e( 'Price multiplier', 'micro-sync-pro' ); ?></label></th>
				<td>
					<input type="text" inputmode="decimal" id="msp-multiplier" name="config[price_multiplier]" class="small-text msp-ltr" dir="ltr" value="<?php echo esc_attr( (string) $opt( 'price_multiplier', '1' ) ); ?>" aria-describedby="msp-multiplier-help">
					<p class="description" id="msp-multiplier-help"><?php esc_html_e( 'Applied to supplier prices before the profit margin, e.g. 0.1 to convert Rial to Toman, or an exchange rate.', 'micro-sync-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Options', 'micro-sync-pro' ); ?></th>
				<td>
					<fieldset>
						<label><input type="checkbox" name="config[clear_sale_when_empty]" value="1" <?php checked( (bool) $opt( 'clear_sale_when_empty', false ) ); ?>> <?php esc_html_e( 'Remove the sale price when the source sale price is empty', 'micro-sync-pro' ); ?></label><br>
						<label><input type="checkbox" name="config[enable_stock_management]" value="1" <?php checked( (bool) $opt( 'enable_stock_management', false ) ); ?>> <?php esc_html_e( 'Enable stock management on products that do not manage stock yet when a quantity is received', 'micro-sync-pro' ); ?></label>
					</fieldset>
				</td>
			</tr>
		</table>
	</div>

	<p class="submit">
		<button type="submit" class="button button-primary"><?php echo esc_html( $is_edit ? __( 'Save changes', 'micro-sync-pro' ) : __( 'Save and detect columns', 'micro-sync-pro' ) ); ?></button>
		<a class="button" href="<?php echo esc_url( View::url( 'msp-sources' ) ); ?>"><?php esc_html_e( 'Cancel', 'micro-sync-pro' ); ?></a>
	</p>
</form>
<?php
View::footer();
