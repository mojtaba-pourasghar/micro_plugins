<?php
/**
 * Data source persistence.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Repository;

use MicroStudio\MicroSyncPro\Database\Schema;
use MicroStudio\MicroSyncPro\Security\Crypto;
use MicroStudio\MicroSyncPro\Security\Redactor;
use MicroStudio\MicroSyncPro\Sources\Source;
use MicroStudio\MicroSyncPro\Sources\SourceTypes;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for sources. Secrets (remote URL, tokens, passwords) are encrypted.
 */
class SourceRepository {

	const SCHEDULES = array( 'none', 'hourly', 'twicedaily', 'daily', 'weekly' );

	/**
	 * Crypto.
	 *
	 * @var Crypto
	 */
	private $crypto;

	/**
	 * Constructor.
	 *
	 * @param Crypto $crypto Crypto.
	 */
	public function __construct( Crypto $crypto ) {
		$this->crypto = $crypto;
	}

	/**
	 * Find by id.
	 *
	 * @param int $id Id.
	 */
	public function find( int $id ): ?Source {
		global $wpdb;
		if ( $id <= 0 ) {
			return null;
		}
		$table = Schema::table( 'sources' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? Source::from_row( $row ) : null;
	}

	/**
	 * All sources.
	 *
	 * @return Source[]
	 */
	public function all(): array {
		global $wpdb;
		$table = Schema::table( 'sources' );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC, id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( Source::class, 'from_row' ), (array) $rows );
	}

	/**
	 * Sources with an active schedule.
	 *
	 * @return Source[]
	 */
	public function scheduled(): array {
		return array_values(
			array_filter(
				$this->all(),
				static function ( Source $s ) {
					return 'active' === $s->status && 'none' !== $s->schedule;
				}
			)
		);
	}

	/**
	 * Insert or update.
	 *
	 * @param Source $source Source.
	 * @return int|WP_Error Id.
	 */
	public function save( Source $source ) {
		global $wpdb;
		$table = Schema::table( 'sources' );
		$now   = current_time( 'mysql', true );
		$data  = array(
			'name'             => mb_substr( sanitize_text_field( $source->name ), 0, 190 ),
			'type'             => in_array( $source->type, SourceTypes::all(), true ) ? $source->type : SourceTypes::CSV_UPLOAD,
			'status'           => 'inactive' === $source->status ? 'inactive' : 'active',
			'config'           => wp_json_encode( $source->config ),
			'mapping'          => wp_json_encode( $source->mapping ),
			'columns_detected' => wp_json_encode( array_values( $source->columns ) ),
			'schedule'         => in_array( $source->schedule, self::SCHEDULES, true ) ? $source->schedule : 'none',
			'last_run_id'      => $source->last_run_id ? $source->last_run_id : null,
			'updated_at'       => $now,
		);
		if ( $source->id > 0 ) {
			$ok = $wpdb->update( $table, $data, array( 'id' => $source->id ) );
			if ( false === $ok ) {
				return new WP_Error( 'msp_db', __( 'The source could not be saved.', 'micro-sync-pro' ) );
			}
			return $source->id;
		}
		$data['created_at'] = $now;
		$ok                 = $wpdb->insert( $table, $data );
		if ( ! $ok ) {
			return new WP_Error( 'msp_db', __( 'The source could not be saved.', 'micro-sync-pro' ) );
		}
		$source->id = (int) $wpdb->insert_id;
		return $source->id;
	}

	/**
	 * Delete a source.
	 *
	 * @param int $id Id.
	 */
	public function delete( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( Schema::table( 'sources' ), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Update the last run pointer.
	 *
	 * @param int $source_id Source.
	 * @param int $run_id    Run.
	 */
	public function set_last_run( int $source_id, int $run_id ): void {
		global $wpdb;
		$wpdb->update( Schema::table( 'sources' ), array( 'last_run_id' => $run_id ), array( 'id' => $source_id ), array( '%d' ), array( '%d' ) );
	}

	/**
	 * Build a validated config array from form input.
	 *
	 * @param string              $type     Type.
	 * @param array<string,mixed> $input    Raw (unslashed) input.
	 * @param Source|null         $existing Existing source (to keep secrets).
	 * @return array<string,mixed>|WP_Error
	 */
	public function build_config( string $type, array $input, ?Source $existing = null ) {
		$old    = $existing ? $existing->config : array();
		$config = array();

		$delimiter           = isset( $input['delimiter'] ) && is_string( $input['delimiter'] ) ? $input['delimiter'] : 'auto';
		$encoding            = isset( $input['encoding'] ) && is_string( $input['encoding'] ) ? $input['encoding'] : 'AUTO';
		$auth                = isset( $input['auth_type'] ) && is_string( $input['auth_type'] ) ? $input['auth_type'] : 'none';
		$config['delimiter'] = in_array( $delimiter, array( 'auto', ',', ';', 'tab', '|' ), true ) ? $delimiter : 'auto';
		$config['encoding']  = in_array( $encoding, array( 'AUTO', 'UTF-8', 'WINDOWS-1256' ), true ) ? $encoding : 'AUTO';
		$config['sheet']     = max( 1, min( 50, absint( $input['sheet'] ?? 1 ) ) );

		$path = trim( sanitize_text_field( (string) ( $input['records_path'] ?? '' ) ) );
		if ( '' !== $path && ! preg_match( '/^[A-Za-z0-9_\-]+(\.[A-Za-z0-9_\-]+)*$/', $path ) ) {
			return new WP_Error( 'msp_records_path', __( 'The records path may contain only letters, digits, "_", "-" and dots.', 'micro-sync-pro' ) );
		}
		$config['records_path'] = $path;

		$config['decimal_separator'] = ',' === ( $input['decimal_separator'] ?? '.' ) ? ',' : '.';
		$mult                        = isset( $input['price_multiplier'] ) ? str_replace( ',', '.', trim( (string) $input['price_multiplier'] ) ) : '1';
		if ( '' === $mult ) {
			$mult = '1';
		}
		if ( ! is_numeric( $mult ) || (float) $mult <= 0 || (float) $mult > 1000000 ) {
			return new WP_Error( 'msp_multiplier', __( 'The price multiplier must be a positive number.', 'micro-sync-pro' ) );
		}
		$config['price_multiplier']        = (string) (float) $mult;
		$config['clear_sale_when_empty']   = ! empty( $input['clear_sale_when_empty'] );
		$config['enable_stock_management'] = ! empty( $input['enable_stock_management'] );

		// Local file reference is set by the upload handler, never from input.
		$config['file']          = (string) ( $old['file'] ?? '' );
		$config['original_name'] = (string) ( $old['original_name'] ?? '' );

		if ( SourceTypes::is_remote( $type ) ) {
			$url = isset( $input['url'] ) ? trim( (string) $input['url'] ) : '';
			if ( '' === $url ) {
				if ( empty( $old['url_enc'] ) ) {
					return new WP_Error( 'msp_url_required', __( 'A remote URL is required for this source type.', 'micro-sync-pro' ) );
				}
				$config['url_enc']     = $old['url_enc'];
				$config['url_display'] = $old['url_display'] ?? '';
			} else {
				$url = esc_url_raw( $url, array( 'http', 'https' ) );
				if ( '' === $url ) {
					return new WP_Error( 'msp_url_invalid', __( 'The URL is not valid.', 'micro-sync-pro' ) );
				}
				$config['url_enc']     = $this->crypto->encrypt( $url );
				$config['url_display'] = Redactor::url( $url );
			}

			$auth                = in_array( $auth, array( 'none', 'bearer', 'basic', 'header' ), true ) ? $auth : 'none';
			$config['auth_type'] = $auth;
			$config['auth_user'] = 'basic' === $auth ? sanitize_text_field( (string) ( $input['auth_user'] ?? '' ) ) : '';
			$header              = trim( (string) ( $input['header_name'] ?? '' ) );
			if ( 'header' === $auth ) {
				if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9\-]{0,63}$/', $header ) || in_array( strtolower( $header ), array( 'host', 'content-length', 'transfer-encoding', 'connection', 'cookie' ), true ) ) {
					return new WP_Error( 'msp_header', __( 'The custom header name is not valid.', 'micro-sync-pro' ) );
				}
			} else {
				$header = '';
			}
			$config['header_name'] = $header;

			$secret = isset( $input['auth_secret'] ) ? trim( (string) $input['auth_secret'] ) : '';
			if ( 'none' === $auth ) {
				$config['auth_secret_enc'] = '';
			} elseif ( '' !== $secret ) {
				if ( strlen( $secret ) > 4096 || preg_match( '/[\r\n\0]/', $secret ) ) {
					return new WP_Error( 'msp_secret', __( 'The credential contains invalid characters.', 'micro-sync-pro' ) );
				}
				$config['auth_secret_enc'] = $this->crypto->encrypt( $secret );
			} else {
				$config['auth_secret_enc'] = (string) ( $old['auth_secret_enc'] ?? '' );
			}
			if ( 'none' !== $auth && '' === $config['auth_secret_enc'] ) {
				return new WP_Error( 'msp_secret_required', __( 'Enter the credential for the selected authentication method.', 'micro-sync-pro' ) );
			}
		}
		return $config;
	}

	/**
	 * Decrypted remote URL.
	 *
	 * @param Source $source Source.
	 */
	public function remote_url( Source $source ): string {
		return $this->crypto->decrypt( (string) $source->opt( 'url_enc', '' ) );
	}

	/**
	 * HTTP headers for authentication (never logged).
	 *
	 * @param Source $source Source.
	 * @return array<string,string>
	 */
	public function auth_headers( Source $source ): array {
		$secret = $this->crypto->decrypt( (string) $source->opt( 'auth_secret_enc', '' ) );
		switch ( $source->opt( 'auth_type', 'none' ) ) {
			case 'bearer':
				return '' === $secret ? array() : array( 'Authorization' => 'Bearer ' . $secret );
			case 'basic':
				return array( 'Authorization' => 'Basic ' . base64_encode( $source->opt( 'auth_user', '' ) . ':' . $secret ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			case 'header':
				$name = (string) $source->opt( 'header_name', '' );
				return ( '' === $name || '' === $secret ) ? array() : array( $name => $secret );
		}
		return array();
	}

	/**
	 * Whether a credential is stored (for UI hints).
	 *
	 * @param Source $source Source.
	 */
	public function has_secret( Source $source ): bool {
		return '' !== (string) $source->opt( 'auth_secret_enc', '' );
	}
}
