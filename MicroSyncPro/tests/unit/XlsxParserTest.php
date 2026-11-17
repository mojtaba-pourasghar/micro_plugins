<?php
/**
 * XLSX parsing tests (workbooks are generated on the fly).
 *
 * @package MicroStudio\MicroSyncPro\Tests
 */

namespace MicroStudio\MicroSyncPro\Tests\Unit;

use MicroStudio\MicroSyncPro\Parser\ParseException;
use MicroStudio\MicroSyncPro\Parser\XlsxParser;
use MicroStudio\MicroSyncPro\Tests\TestCase;

/**
 * @covers \MicroStudio\MicroSyncPro\Parser\XlsxParser
 */
class XlsxParserTest extends TestCase {

	/**
	 * Build a minimal XLSX workbook.
	 *
	 * @param string $sheet_xml sheetData inner XML.
	 * @param string[] $shared  Shared strings.
	 */
	public static function make_xlsx( string $sheet_xml, array $shared, string $path ): string {
		$zip = new \ZipArchive();
		$zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		$zip->addFromString( '[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/></Types>' );
		$zip->addFromString( 'xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Prices" sheetId="1" r:id="rId1"/></sheets></workbook>' );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/data.xml"/></Relationships>' );
		$ss = '';
		foreach ( $shared as $s ) {
			$ss .= '<si><t>' . htmlspecialchars( $s, ENT_XML1 ) . '</t></si>';
		}
		$zip->addFromString( 'xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count( $shared ) . '">' . $ss . '<si><r><t>Rich </t></r><r><t>text</t></r></si></sst>' );
		$zip->addFromString( 'xl/worksheets/data.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $sheet_xml . '</sheetData></worksheet>' );
		$zip->close();
		return $path;
	}

	private function workbook(): string {
		$sheet = '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="inlineStr"><is><t>stock</t></is></c><c r="D1" t="s"><v>4</v></c></row>'
			. '<row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2"><v>1250000</v></c><c r="C2"><v>7</v></c><c r="D2" t="s"><v>5</v></c></row>'
			. '<row r="3"><c r="A3"/></row>'
			. '<row r="5"><c r="A5" t="s"><v>3</v></c><c r="B5" t="n"><v>0.30000000000000004</v></c><c r="D5" t="b"><v>1</v></c></row>';
		return self::make_xlsx( $sheet, array( 'sku', 'price', 'کد-۱', 'B-2', 'note' ), $this->temp_file( '', '.xlsx' ) );
	}

	public function test_headers_from_shared_and_inline_strings(): void {
		$this->assertSame( array( 'sku', 'price', 'stock', 'note' ), ( new XlsxParser() )->headers( $this->workbook() ) );
	}

	public function test_rows_with_real_row_numbers_sparse_cells_and_number_cleanup(): void {
		$rows = iterator_to_array( ( new XlsxParser() )->rows( $this->workbook() ) );
		$this->assertSame( array( 2, 5 ), array_keys( $rows ), 'Empty row 3 is skipped; numbering follows the sheet.' );
		$this->assertSame( array( 'sku' => 'کد-۱', 'price' => '1250000', 'stock' => '7', 'note' => 'Rich text' ), $rows[2] );
		$this->assertSame( '0.3', $rows[5]['price'] );
		$this->assertSame( '', $rows[5]['stock'] );
		$this->assertSame( '1', $rows[5]['note'] );
	}

	public function test_column_index(): void {
		$this->assertSame( 0, XlsxParser::column_index( 'A1' ) );
		$this->assertSame( 25, XlsxParser::column_index( 'Z9' ) );
		$this->assertSame( 27, XlsxParser::column_index( 'AB12' ) );
	}

	public function test_invalid_workbook_throws(): void {
		$this->expectException( ParseException::class );
		( new XlsxParser() )->headers( $this->temp_file( 'not a zip', '.xlsx' ) );
	}

	public function test_missing_sheet_throws(): void {
		$this->expectException( ParseException::class );
		( new XlsxParser() )->headers( $this->workbook(), array( 'sheet' => 3 ) );
	}
}
