<?php
/**
 * Header cleanup shared by parsers.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Parser;

defined( 'ABSPATH' ) || exit;

/**
 * Cleans header cells and makes them unique.
 */
final class HeaderNormalizer {

	const MAX_COLUMNS = 250;

	/**
	 * Normalize a header row.
	 *
	 * @param array<int,mixed> $cells Raw cells.
	 * @return string[]
	 */
	public static function normalize( array $cells ): array {
		$out  = array();
		$seen = array();
		$i    = 0;
		foreach ( $cells as $cell ) {
			++$i;
			if ( $i > self::MAX_COLUMNS ) {
				break;
			}
			$name = self::clean( (string) $cell );
			if ( '' === $name ) {
				$name = 'column_' . $i;
			}
			$base = $name;
			$n    = 2;
			while ( isset( $seen[ $name ] ) ) {
				$name = $base . '_' . $n;
				++$n;
			}
			$seen[ $name ] = true;
			$out[]         = $name;
		}
		return $out;
	}

	/**
	 * Clean one header: strip BOM, control chars, trim, limit length.
	 *
	 * @param string $name Header.
	 */
	public static function clean( string $name ): string {
		$name = preg_replace( '/^\xEF\xBB\xBF/', '', $name );
		$name = (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', (string) $name );
		$name = trim( $name );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $name, 0, 100 );
		}
		return substr( $name, 0, 100 );
	}
}
