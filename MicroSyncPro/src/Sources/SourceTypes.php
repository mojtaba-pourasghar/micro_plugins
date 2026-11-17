<?php
/**
 * Source type registry.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * Supported source types.
 */
final class SourceTypes {

	const CSV_UPLOAD  = 'csv_upload';
	const XLSX_UPLOAD = 'xlsx_upload';
	const CSV_URL     = 'csv_url';
	const JSON_URL    = 'json_url';

	/**
	 * Labels.
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		$labels = array(
			self::CSV_UPLOAD => __( 'CSV file upload', 'micro-sync-pro' ),
			self::CSV_URL    => __( 'Remote CSV URL', 'micro-sync-pro' ),
			self::JSON_URL   => __( 'Remote JSON URL / supplier API', 'micro-sync-pro' ),
		);
		if ( self::xlsx_supported() ) {
			$labels = array_slice( $labels, 0, 1, true ) + array( self::XLSX_UPLOAD => __( 'Excel (XLSX) file upload', 'micro-sync-pro' ) ) + $labels;
		}
		return $labels;
	}

	/**
	 * All valid types.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array_keys( self::labels() );
	}

	/**
	 * Whether XLSX parsing is available on this server.
	 */
	public static function xlsx_supported(): bool {
		return class_exists( 'ZipArchive' ) && class_exists( 'XMLReader' );
	}

	/**
	 * File format for a type.
	 *
	 * @param string $type Type.
	 */
	public static function format( string $type ): string {
		switch ( $type ) {
			case self::XLSX_UPLOAD:
				return 'xlsx';
			case self::JSON_URL:
				return 'json';
			default:
				return 'csv';
		}
	}

	/**
	 * Whether the type is remote.
	 *
	 * @param string $type Type.
	 */
	public static function is_remote( string $type ): bool {
		return in_array( $type, array( self::CSV_URL, self::JSON_URL ), true );
	}
}
