<?php
/**
 * Symmetric encryption for stored credentials.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts secrets (API tokens, passwords, sensitive URLs) at rest using
 * libsodium secretbox with a key derived from the site's secret salts.
 */
class Crypto {

	const PREFIX = 'msp1:';

	/**
	 * Derive the key.
	 */
	private function key(): string {
		$material = wp_salt( 'auth' ) . '|micro-sync-pro|' . wp_salt( 'secure_auth' );
		return sodium_crypto_generichash( $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Encrypt a string. Empty input yields empty output.
	 *
	 * @param string $plain Plain text.
	 */
	public function encrypt( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plain, $nonce, $this->key() );
		return self::PREFIX . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt. Returns '' when the value cannot be decrypted (e.g. salts changed).
	 *
	 * @param string $encoded Encoded cipher text.
	 */
	public function decrypt( string $encoded ): string {
		if ( '' === $encoded || 0 !== strpos( $encoded, self::PREFIX ) ) {
			return '';
		}
		$raw = base64_decode( substr( $encoded, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $this->key() );
		return false === $plain ? '' : $plain;
	}
}
