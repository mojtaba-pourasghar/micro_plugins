<?php
/**
 * Redaction of sensitive data before logging or display.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Removes credentials from URLs, messages and context arrays.
 */
final class Redactor {

	/** Keys whose values are never logged. */
	const SENSITIVE_KEYS = array( 'password', 'pass', 'secret', 'token', 'auth', 'authorization', 'api_key', 'apikey', 'key', 'auth_secret', 'header_value', 'cookie', 'url' );

	/**
	 * Reduce a URL to scheme://host/…  (no credentials, path details or query).
	 *
	 * @param string $url URL.
	 */
	public static function url( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '[invalid-url]';
		}
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'http';
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path   = isset( $parts['path'] ) && '' !== $parts['path'] && '/' !== $parts['path'] ? '/…' : '';
		$query  = isset( $parts['query'] ) ? '?…' : '';
		return $scheme . '://' . strtolower( $parts['host'] ) . $port . $path . $query;
	}

	/**
	 * Redact URLs and bearer-like tokens from free text.
	 *
	 * @param string $text Text.
	 */
	public static function text( string $text ): string {
		$text = (string) preg_replace_callback(
			'#\bhttps?://[^\s"\'<>]+#i',
			static function ( $m ) {
				return self::url( $m[0] );
			},
			$text
		);
		$text = (string) preg_replace( '/\b(Bearer|Basic|Token)\s+[A-Za-z0-9\-._~+\/=]{6,}/i', '$1 [redacted]', $text );
		return $text;
	}

	/**
	 * Recursively redact a context array.
	 *
	 * @param mixed $context Context.
	 * @param int   $depth   Depth guard.
	 * @return mixed
	 */
	public static function context( $context, int $depth = 0 ) {
		if ( $depth > 5 ) {
			return '[depth-limit]';
		}
		if ( is_array( $context ) ) {
			$out = array();
			foreach ( $context as $k => $v ) {
				$lk = strtolower( (string) $k );
				if ( in_array( $lk, self::SENSITIVE_KEYS, true ) ) {
					$out[ $k ] = ( 'url' === $lk && is_string( $v ) ) ? self::url( $v ) : '[redacted]';
					continue;
				}
				$out[ $k ] = self::context( $v, $depth + 1 );
			}
			return $out;
		}
		if ( is_string( $context ) ) {
			return self::text( $context );
		}
		if ( is_scalar( $context ) || null === $context ) {
			return $context;
		}
		return '[object]';
	}
}
