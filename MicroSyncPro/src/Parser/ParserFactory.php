<?php
/**
 * Parser factory.
 *
 * @package MicroStudio\MicroSyncPro
 */

namespace MicroStudio\MicroSyncPro\Parser;

defined( 'ABSPATH' ) || exit;

/**
 * Returns the parser for a file format.
 */
class ParserFactory {

	/**
	 * Get parser.
	 *
	 * @param string $format csv|xlsx|json.
	 * @throws ParseException When unsupported.
	 */
	public function for_format( string $format ): ParserInterface {
		switch ( $format ) {
			case 'csv':
				return new CsvParser();
			case 'xlsx':
				return new XlsxParser();
			case 'json':
				return new JsonParser();
		}
		throw new ParseException( __( 'Unsupported file format.', 'micro-sync-pro' ) );
	}
}
