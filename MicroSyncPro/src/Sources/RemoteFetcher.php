<?php
/**
 * Remote source download using the WordPress HTTP API.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Sources;

use MicroStudio\MicroSyncPro\Security\Redactor;
use MicroStudio\MicroSyncPro\Security\UploadHandler;
use MicroStudio\MicroSyncPro\Security\UrlValidator;
use MicroStudio\MicroSyncPro\Settings\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Downloads a remote CSV/JSON document to protected storage, streaming to disk.
 * Every hop (including redirects) is validated against SSRF rules and the
 * connection is pinned to the validated IP to prevent DNS rebinding.
 */
class RemoteFetcher {

	const MAX_REDIRECTS = 3;

	/**
	 * Allowed content types per format.
	 *
	 * @var array<string,string[]>
	 */
	const CONTENT_TYPES = array(
		'csv'  => array( 'text/csv', 'text/plain', 'application/csv', 'text/comma-separated-values', 'application/vnd.ms-excel', 'application/octet-stream', 'text/x-csv' ),
		'json' => array( 'application/json', 'text/json', 'text/plain', 'application/octet-stream', 'application/ld+json', 'application/hal+json', 'application/vnd.api+json' ),
	);

	/** @var UrlValidator */
	private $validator;
	/** @var Settings */
	private $settings;
	/** @var Storage */
	private $storage;
	/** @var array{host:string,port:int,ip:string,url:string}|null */
	private $pin = null;

	/**
	 * Constructor.
	 *
	 * @param UrlValidator $validator Validator.
	 * @param Settings     $settings  Settings.
	 * @param Storage      $storage   Storage.
	 */
	public function __construct( UrlValidator $validator, Settings $settings, Storage $storage ) {
		$this->validator = $validator;
		$this->settings  = $settings;
		$this->storage   = $storage;
	}

	/**
	 * Download a URL.
	 *
	 * @param string               $url     URL.
	 * @param array<string,string> $headers Extra headers (auth).
	 * @param string               $format  csv|json.
	 * @return string|WP_Error Stored file name.
	 */
	public function download( string $url, array $headers, string $format ) {
		if ( ! isset( self::CONTENT_TYPES[ $format ] ) ) {
			return new WP_Error( 'msp_remote_format', __( 'Unsupported remote format.', 'micro-sync-pro' ) );
		}
		if ( ! $this->storage->ensure_directory() ) {
			return new WP_Error( 'msp_storage', __( 'The storage directory could not be created.', 'micro-sync-pro' ) );
		}
		$max_bytes = (int) $this->settings->get( 'max_remote_mb' ) * MB_IN_BYTES;
		$name      = $this->storage->new_name( $format, 'r' );
		$target    = $this->storage->path( $name );
		$current   = $url;
		$origin    = wp_parse_url( $url, PHP_URL_HOST );

		for ( $hop = 0; $hop <= self::MAX_REDIRECTS; $hop++ ) {
			$check = $this->validator->validate( $current );
			if ( is_wp_error( $check ) ) {
				$this->storage->delete( $name );
				return $check;
			}
			// Do not forward credentials to a different host after a redirect.
			$send_headers = ( $hop > 0 && strtolower( (string) $check['host'] ) !== strtolower( (string) $origin ) ) ? array() : $headers;

			$this->pin = array(
				'host' => $check['host'],
				'port' => $check['port'],
				'ip'   => $check['ips'][0],
				'url'  => $current,
			);
			add_action( 'http_api_curl', array( $this, 'pin_curl' ), 10, 3 );
			$response = wp_safe_remote_get(
				$current,
				array(
					'timeout'             => (int) $this->settings->get( 'http_timeout' ),
					'redirection'         => 0,
					'stream'              => true,
					'filename'            => $target,
					'limit_response_size' => $max_bytes + 1,
					'reject_unsafe_urls'  => true,
					'decompress'          => true,
					'sslverify'           => true,
					'user-agent'          => 'MicroSyncPro/' . MSP_VERSION . '; WordPress',
					'headers'             => array_merge(
						array( 'Accept' => 'json' === $format ? 'application/json' : 'text/csv, text/plain;q=0.9, */*;q=0.1' ),
						$send_headers
					),
				)
			);
			remove_action( 'http_api_curl', array( $this, 'pin_curl' ), 10 );
			$this->pin = null;

			if ( is_wp_error( $response ) ) {
				$this->storage->delete( $name );
				return new WP_Error(
					'msp_remote_http',
					sprintf(
						/* translators: %s: error message. */
						__( 'The remote source could not be downloaded: %s', 'micro-sync-pro' ),
						Redactor::text( $response->get_error_message() )
					)
				);
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
				$location = (string) wp_remote_retrieve_header( $response, 'location' );
				if ( '' === $location ) {
					break;
				}
				$current = \WP_Http::make_absolute_url( $location, $current );
				continue;
			}
			if ( 200 !== $code ) {
				$this->storage->delete( $name );
				return new WP_Error(
					'msp_remote_status',
					sprintf(
						/* translators: %d: HTTP status code. */
						__( 'The remote server responded with HTTP status %d.', 'micro-sync-pro' ),
						$code
					)
				);
			}
			$valid = $this->validate_body( $target, (string) wp_remote_retrieve_header( $response, 'content-type' ), $format, $max_bytes );
			if ( is_wp_error( $valid ) ) {
				$this->storage->delete( $name );
				return $valid;
			}
			return $name;
		}
		$this->storage->delete( $name );
		return new WP_Error( 'msp_remote_redirects', __( 'The remote source redirected too many times.', 'micro-sync-pro' ) );
	}

	/**
	 * Validate the downloaded body.
	 *
	 * @param string $path         File.
	 * @param string $content_type Content-Type header.
	 * @param string $format       Format.
	 * @param int    $max_bytes    Size limit.
	 * @return true|WP_Error
	 */
	public function validate_body( string $path, string $content_type, string $format, int $max_bytes ) {
		if ( ! is_file( $path ) ) {
			return new WP_Error( 'msp_remote_empty', __( 'The remote source returned an empty response.', 'micro-sync-pro' ) );
		}
		clearstatcache( true, $path );
		$size = (int) filesize( $path );
		if ( $size <= 0 ) {
			return new WP_Error( 'msp_remote_empty', __( 'The remote source returned an empty response.', 'micro-sync-pro' ) );
		}
		if ( $size > $max_bytes ) {
			return new WP_Error( 'msp_remote_size', __( 'The remote response exceeds the maximum allowed size.', 'micro-sync-pro' ) );
		}
		$mime = strtolower( trim( explode( ';', $content_type )[0] ) );
		if ( '' !== $mime && ! in_array( $mime, self::CONTENT_TYPES[ $format ], true ) && ! ( 'json' === $format && preg_match( '#^application/[a-z0-9.\-]+\+json$#', $mime ) ) ) {
			return new WP_Error(
				'msp_remote_type',
				sprintf(
					/* translators: %s: content type. */
					__( 'Unexpected content type from the remote source: %s', 'micro-sync-pro' ),
					$mime
				)
			);
		}
		if ( ! UploadHandler::looks_like_text( $path ) ) {
			return new WP_Error( 'msp_remote_content', __( 'The remote response is not a valid text document.', 'micro-sync-pro' ) );
		}
		$fh    = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$start = ltrim( (string) fread( $fh, 512 ), "\xEF\xBB\xBF \t\r\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( 'json' === $format && ! in_array( substr( $start, 0, 1 ), array( '{', '[' ), true ) ) {
			return new WP_Error( 'msp_remote_content', __( 'The remote response is not JSON.', 'micro-sync-pro' ) );
		}
		if ( 'csv' === $format && preg_match( '/^<(!doctype|html|\?xml)/i', $start ) ) {
			return new WP_Error( 'msp_remote_content', __( 'The remote response is an HTML/XML page, not CSV.', 'micro-sync-pro' ) );
		}
		return true;
	}

	/**
	 * Pin the cURL connection to the validated IP (prevents DNS rebinding).
	 *
	 * @param resource|\CurlHandle $handle cURL handle.
	 * @param array<string,mixed>  $args   Request args.
	 * @param string               $url    URL.
	 */
	public function pin_curl( $handle, $args, $url ): void {
		if ( null === $this->pin || $url !== $this->pin['url'] || ! defined( 'CURLOPT_RESOLVE' ) ) {
			return;
		}
		if ( filter_var( $this->pin['host'], FILTER_VALIDATE_IP ) ) {
			return;
		}
		$ip = $this->pin['ip'];
		if ( false !== strpos( $ip, ':' ) ) {
			$ip = '[' . $ip . ']';
		}
		curl_setopt( $handle, CURLOPT_RESOLVE, array( $this->pin['host'] . ':' . $this->pin['port'] . ':' . $ip ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
	}
}
