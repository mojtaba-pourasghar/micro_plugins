<?php
/**
 * Parser contract.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Parser;

defined( 'ABSPATH' ) || exit;

/**
 * Streaming parser: yields one associative row at a time so large files are
 * never loaded into memory as a whole (JSON is size-limited instead).
 */
interface ParserInterface {

	/**
	 * Iterate rows. Keys are source row numbers (1-based, as shown in a spreadsheet).
	 *
	 * @param string              $path    File path.
	 * @param array<string,mixed> $options Parser options.
	 * @return \Generator<int,array<string,string>>
	 * @throws ParseException On unreadable or malformed input.
	 */
	public function rows( string $path, array $options = array() ): \Generator;

	/**
	 * Column names detected in the file.
	 *
	 * @param string              $path    File path.
	 * @param array<string,mixed> $options Parser options.
	 * @return string[]
	 * @throws ParseException On unreadable or malformed input.
	 */
	public function headers( string $path, array $options = array() ): array;
}
