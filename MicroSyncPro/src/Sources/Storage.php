<?php
/**
 * Protected file storage for uploaded and downloaded source files.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * Stores files under a protected directory with random, validated names.
 * File names from the browser are never used as paths.
 */
class Storage {

	const NAME_PATTERN = '/^[a-z0-9][a-z0-9_-]{7,80}\.(csv|xlsx|json|jsonl|tmp)$/';

	/**
	 * Base directory (no trailing slash).
	 */
	public function base_dir(): string {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . 'micro-sync-pro';
		/**
		 * Filter the storage directory. Point it outside the web root for maximum protection.
		 *
		 * @param string $dir Directory.
		 */
		return untrailingslashit( (string) apply_filters( 'micro_sync_pro_storage_dir', $dir ) );
	}

	/**
	 * Create the directory and protection files.
	 */
	public function ensure_directory(): bool {
		$dir = $this->base_dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$files = array(
			'.htaccess'  => "# Micro Sync Pro: deny direct access\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);
		foreach ( $files as $name => $content ) {
			$path = $dir . '/' . $name;
			if ( ! file_exists( $path ) ) {
				file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
		return true;
	}

	/**
	 * Generate a new random file name.
	 *
	 * @param string $ext    Extension.
	 * @param string $prefix Prefix (a-z only).
	 */
	public function new_name( string $ext, string $prefix = 'f' ): string {
		$ext    = in_array( $ext, array( 'csv', 'xlsx', 'json', 'jsonl', 'tmp' ), true ) ? $ext : 'tmp';
		$prefix = preg_replace( '/[^a-z]/', '', strtolower( $prefix ) );
		return ( $prefix ? $prefix : 'f' ) . '-' . bin2hex( random_bytes( 16 ) ) . '.' . $ext;
	}

	/**
	 * Whether a stored name is syntactically valid.
	 *
	 * @param string $name Name.
	 */
	public function is_valid_name( string $name ): bool {
		return (bool) preg_match( self::NAME_PATTERN, $name );
	}

	/**
	 * Absolute path for a stored name, or '' if the name is invalid.
	 *
	 * @param string $name Name.
	 */
	public function path( string $name ): string {
		if ( ! $this->is_valid_name( $name ) ) {
			return '';
		}
		return $this->base_dir() . '/' . $name;
	}

	/**
	 * Whether the stored file exists.
	 *
	 * @param string $name Name.
	 */
	public function exists( string $name ): bool {
		$path = $this->path( $name );
		return '' !== $path && is_file( $path );
	}

	/**
	 * Delete a stored file.
	 *
	 * @param string $name Name.
	 */
	public function delete( string $name ): void {
		$path = $this->path( $name );
		if ( '' !== $path && is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Copy a stored file to a new name.
	 *
	 * @param string $name Source name.
	 * @param string $ext  Target extension.
	 * @return string New name or ''.
	 */
	public function duplicate( string $name, string $ext ): string {
		$src = $this->path( $name );
		if ( '' === $src || ! is_file( $src ) ) {
			return '';
		}
		$new = $this->new_name( $ext, 's' );
		return copy( $src, $this->path( $new ) ) ? $new : '';
	}

	/**
	 * Remove stale temporary files older than $age seconds, except protected names.
	 *
	 * @param int      $age  Age in seconds.
	 * @param string[] $keep Names in use.
	 */
	public function cleanup( int $age, array $keep ): int {
		$dir = $this->base_dir();
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		$removed = 0;
		$keep    = array_flip( $keep );
		foreach ( (array) scandir( $dir ) as $file ) {
			if ( ! is_string( $file ) || ! $this->is_valid_name( $file ) || isset( $keep[ $file ] ) ) {
				continue;
			}
			$path = $dir . '/' . $file;
			if ( is_file( $path ) && filemtime( $path ) < time() - $age ) {
				wp_delete_file( $path );
				++$removed;
			}
		}
		return $removed;
	}
}
