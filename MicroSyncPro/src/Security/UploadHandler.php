<?php
/**
 * Secure upload handling for local source files.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Security;

use MicroStudio\MicroSyncPro\Sources\Storage;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Validates an uploaded CSV/XLSX file (error code, size, extension, MIME and
 * content signature) and moves it into protected storage under a random name.
 */
class UploadHandler {

	/**
	 * Storage.
	 *
	 * @var Storage
	 */
	private $storage;

	/**
	 * Max bytes.
	 *
	 * @var int
	 */
	private $max_bytes;

	/**
	 * Constructor.
	 *
	 * @param Storage $storage   Storage.
	 * @param int     $max_bytes Max size in bytes.
	 */
	public function __construct( Storage $storage, int $max_bytes ) {
		$this->storage   = $storage;
		$this->max_bytes = max( 1, $max_bytes );
	}

	/**
	 * Handle an entry from $_FILES.
	 *
	 * @param array<string,mixed> $file   File array.
	 * @param string              $format csv|xlsx.
	 * @return array{name:string,original:string,size:int}|WP_Error
	 */
	public function handle( array $file, string $format ) {
		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_NO_FILE === $error ) {
			return new WP_Error( 'msp_upload_none', __( 'No file was uploaded.', 'micro-sync-pro' ) );
		}
		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			return new WP_Error( 'msp_upload_size', __( 'The uploaded file exceeds the maximum allowed size.', 'micro-sync-pro' ) );
		}
		if ( UPLOAD_ERR_OK !== $error ) {
			return new WP_Error( 'msp_upload_error', __( 'The file upload failed. Please try again.', 'micro-sync-pro' ) );
		}
		$tmp  = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$name = isset( $file['name'] ) ? sanitize_file_name( wp_basename( (string) $file['name'] ) ) : '';
		if ( '' === $tmp || ! $this->is_uploaded( $tmp ) ) {
			return new WP_Error( 'msp_upload_invalid', __( 'Invalid upload.', 'micro-sync-pro' ) );
		}
		$size = (int) filesize( $tmp );
		if ( $size <= 0 ) {
			return new WP_Error( 'msp_upload_empty', __( 'The uploaded file is empty.', 'micro-sync-pro' ) );
		}
		if ( $size > $this->max_bytes ) {
			return new WP_Error( 'msp_upload_size', __( 'The uploaded file exceeds the maximum allowed size.', 'micro-sync-pro' ) );
		}

		$valid = $this->validate_file( $tmp, $name, $format );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( ! $this->storage->ensure_directory() ) {
			return new WP_Error( 'msp_storage', __( 'The storage directory could not be created.', 'micro-sync-pro' ) );
		}
		$stored = $this->storage->new_name( $format, 'u' );
		$dest   = $this->storage->path( $stored );
		if ( ! $this->move( $tmp, $dest ) ) {
			return new WP_Error( 'msp_upload_move', __( 'The uploaded file could not be saved.', 'micro-sync-pro' ) );
		}
		@chmod( $dest, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		return array(
			'name'     => $stored,
			'original' => $name,
			'size'     => $size,
		);
	}

	/**
	 * Validate extension, MIME and content signature.
	 *
	 * @param string $path   Path.
	 * @param string $name   Original file name.
	 * @param string $format csv|xlsx.
	 * @return true|WP_Error
	 */
	public function validate_file( string $path, string $name, string $format ) {
		$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( 'csv' === $format ) {
			if ( ! in_array( $ext, array( 'csv', 'txt' ), true ) ) {
				return new WP_Error( 'msp_upload_type', __( 'Only .csv files are allowed for this source.', 'micro-sync-pro' ) );
			}
			$mimes = array(
				'csv' => 'text/csv',
				'txt' => 'text/plain',
			);
			$check = wp_check_filetype_and_ext( $path, $name, $mimes );
			if ( empty( $check['ext'] ) ) {
				return new WP_Error( 'msp_upload_type', __( 'The file type is not allowed.', 'micro-sync-pro' ) );
			}
			return self::looks_like_text( $path ) ? true : new WP_Error( 'msp_upload_content', __( 'The file does not look like a CSV text file.', 'micro-sync-pro' ) );
		}
		if ( 'xlsx' === $format ) {
			if ( 'xlsx' !== $ext ) {
				return new WP_Error( 'msp_upload_type', __( 'Only .xlsx files are allowed for this source.', 'micro-sync-pro' ) );
			}
			return self::looks_like_xlsx( $path ) ? true : new WP_Error( 'msp_upload_content', __( 'The file is not a valid XLSX workbook.', 'micro-sync-pro' ) );
		}
		return new WP_Error( 'msp_upload_type', __( 'The file type is not allowed.', 'micro-sync-pro' ) );
	}

	/**
	 * Text heuristic: no NUL bytes and valid-ish encoding in the first 64 KB; no PHP tags.
	 *
	 * @param string $path Path.
	 */
	public static function looks_like_text( string $path ): bool {
		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return false;
		}
		$chunk = (string) fread( $fh, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( '' === $chunk || false !== strpos( $chunk, "\0" ) ) {
			return false;
		}
		if ( preg_match( '/<\?php|<\?=|<script\b/i', $chunk ) ) {
			return false;
		}
		return true;
	}

	/**
	 * XLSX heuristic: ZIP signature with workbook parts.
	 *
	 * @param string $path Path.
	 */
	public static function looks_like_xlsx( string $path ): bool {
		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return false;
		}
		$sig = fread( $fh, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( "PK\x03\x04" !== $sig || ! class_exists( 'ZipArchive' ) ) {
			return false;
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::RDONLY ) ) {
			return false;
		}
		$ok = false !== $zip->locateName( '[Content_Types].xml' ) && false !== $zip->locateName( 'xl/workbook.xml' );
		// Reject archives that contain executable payloads.
		for ( $i = 0; $ok && $i < $zip->numFiles; $i++ ) {
			$entry = (string) $zip->getNameIndex( $i );
			if ( preg_match( '/\.(php\d?|phtml|phar|js|exe|sh)$/i', $entry ) || false !== strpos( $entry, '..' ) ) {
				$ok = false;
			}
		}
		$zip->close();
		return $ok;
	}

	/**
	 * Whether the file is a genuine HTTP upload. Overridable in tests.
	 *
	 * @param string $tmp Temp path.
	 */
	protected function is_uploaded( string $tmp ): bool {
		return is_uploaded_file( $tmp );
	}

	/**
	 * Move the file. Overridable in tests.
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 */
	protected function move( string $from, string $to ): bool {
		return @move_uploaded_file( $from, $to ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
}
