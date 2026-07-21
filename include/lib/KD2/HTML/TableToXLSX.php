<?php

namespace KD2\HTML;

use KD2\HTML\CSSParser;
use KD2\ZipWriter;

use DOMDocument;
use DOMNode;


/**
 * This class takes one or more HTML tables, and convert them to a single XLSX document.
 *
 * - support for colspan and rowspan
 * - automatic column width
 * - each table is handled as a sheet, the <caption> will act as the name of the sheet
 * - detection of cell type, or force cell type using '-spreadsheet-cell-type' CSS property,
 *   or using the 'data-spreadsheet-type' HTML attribute
 * - provide the real number via the "data-spreadsheet-value" HTML attribute
 *   (eg. if the number is displayed as a graph, or something like that)
 * - provide the real date via the "data-spreadsheet-value" attribute
 * - locale setting using '-spreadsheet-locale' CSS property on <table>
 *
 * What is NOT supported currently:
 * - styles (use ODS export instead)
 * - formulas
 * - currencies
 * - conditional formatting
 *
 * This supports a number of custom CSS properties (note the leading dash '-').
 * See TableToODS::CUSTOM_CSS_PROPERTIES for details.
 * Note that those properties are also cascading.
 *
 * Note: CSS selectors support is limited to tag names, classes and IDs.
 * See KD2/HTML/CSSParser for details.
 *
 * @author bohwaz <https://bohwaz.net/>
 * @see http://officeopenxml.com/SScontentOverview.php
 */

/*
	This software is free software: you can redistribute it and/or modify
	it under the terms of the GNU Affero General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	This software is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU Affero General Public License for more details.

	You should have received a copy of the GNU Affero General Public License
	along with this software.  If not, see <https://www.gnu.org/licenses/>.
*/
class TableToXLSX extends TableToODS
{
	protected array $sheets = [];
	protected array $merged_cells = [];
	protected array $styles = [];

	const XML_HEADER = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';

	const MIME_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
	const EXTENSION = 'xlsx';

	public function openTable(string $sheet_name, array $styles = []): void
	{
		$this->xml = self::XML_HEADER;
		$this->xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:x14="http://schemas.microsoft.com/office/spreadsheetml/2009/9/main" xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing"><sheetPr filterMode="false"><pageSetUpPr fitToPage="false"/></sheetPr>';

		$this->styles = $styles;
		$this->sheets[$sheet_name] = '';
		$this->columns_widths = [];
		$this->row_index = 1;
		$this->rows = [];
		$this->table_name = $sheet_name;
	}

	public function closeTable(): void
	{
		$this->xml .= sprintf('<dimension ref="A1:%s"/>', $this->cellName());
		$this->xml .= '<sheetViews>
			<sheetView colorId="64" defaultGridColor="true" rightToLeft="false" showFormulas="false" showGridLines="true" showOutlineSymbols="true" showRowColHeaders="true" showZeros="true" tabSelected="true" topLeftCell="A1" view="normal" workbookViewId="0" zoomScale="100" zoomScaleNormal="100" zoomScalePageLayoutView="100">
				<selection activeCell="A1" activeCellId="0" pane="topLeft" sqref="A1"/>
			</sheetView>
		</sheetViews>
		<sheetFormatPr defaultColWidth="11.53515625" defaultRowHeight="12.8" outlineLevelCol="0" outlineLevelRow="0" zeroHeight="false"/>';

		$this->xml .= '<cols>';

		ksort($this->columns_widths);

		foreach ($this->columns_widths as $i => $width) {
			$this->xml .= sprintf('<col collapsed="false" customWidth="true" hidden="false" max="%d" min="%1$d" outlineLevel="0" style="0" width="%s"/>',
				$i + 1, $width / 5.5);
		}

		$this->xml .= '</cols>';
		$this->xml .= '<sheetData>';

		foreach ($this->rows as $i => $row) {
			$this->xml .= sprintf('<row collapsed="false" customFormat="false" customHeight="false" hidden="false" ht="12.8" outlineLevel="0" r="%d">',
				$i);

			$this->xml .= "\n";

			ksort($row);
			unset($row['styles']);

			foreach ($row as $cell) {
				if ('' === $cell) {
					continue;
				}

				$this->xml .= $cell;
				$this->xml .= "\n";
			}

			$this->xml .= '</row>';
			$this->xml .= "\n";
		}

		$this->xml .= '</sheetData>';

		// Merge cells
		if ($count = count($this->merged_cells)) {
			$this->xml .= sprintf('<mergeCells count="%d">', $count);

			foreach ($this->merged_cells as $cell) {
				$this->xml .= sprintf('<mergeCell ref="%s"/>', $cell);
			}

			$this->xml .= '</mergeCells>';
		}

		$this->xml .= '</worksheet>';

		end($this->sheets);
		$this->sheets[key($this->sheets)] = $this->xml;
		$this->xml = '';

		$this->columns_widths = [];
		$this->rows = [];
		$this->row_index = 1;
	}

	protected function cellName(int $col = null, int $row = null): string
	{
		$col ??= $this->col_index;
		$row ??= $this->row_index;

		$n = $col;

		// Create column name
		for ($r = ''; $n >= 0; $n = intval($n / 26) - 1) {
			$r = chr($n%26 + 0x41) . $r;
		}

		return $r . $row;
	}

	public function appendCell($value, ?string $html = null, array $styles = [], array $attributes = []): void
	{
		// Skip rowspan
		while (isset($this->rows[$this->row_index][$this->col_index])) {
			$this->col_index++;
		}

		$value = trim($value);
		$colspan = intval($attributes['colspan'] ?? 1);
		$rowspan = intval($attributes['rowspan'] ?? 1);

		$s = 0;

		if ($value === '') {
			// Handle empty cells
			$column_width = 10;
			$cell = sprintf('<c r="%s" s="%d" />', $this->cellName(), $s);
		}
		else {
			$date = null;
			$number_value = null;
			$type = $this->getCellType($value, $attributes, $styles, $date, $number_value);

			// Use real type in styles, useful to set the correct style
			$styles['-spreadsheet-cell-type'] = $type;

			// See styles.xml <cellXfs> for $s number
			if ($type === 'date') {
				// Cannot use ISO dates as Gnumeric does not read them
				// https://www.ericwhite.com/blog/dates-in-strict-spreadsheetml-files/
				//$t = 'd';
				$t = 'n';

				// Use number of days since January 1, 1900
				// https://www.ericwhite.com/blog/dates-in-spreadsheetml/
				$d = strtotime($date);
				$value = juliantojd(date('m', $d), date('d', $d), date('Y', $d)) - juliantojd(1, 1, 1900);

				// Add a one day, as 1900 is incorrectly considered as a leap-year
				$value += 1;

				if (date('His', $d) !== '000000') {
					$s = 4;
					// Add hours, minutes, and seconds
					$value += (date('H', $d) + (date('i', $d) + (date('s', $d) / 60)) / 60) / 24;
				}
				else {
					$s = 1;
				}
			}
			elseif ($type === 'percentage') {
				$value = $number_value / 100;
				$t = 'n';
				$s = 3;
			}
			elseif ($type === 'number') {
				$value = $number_value;
				$t = 'n';
				$s = 0;
			}
			elseif ($type === 'currency') {
				$value = $number_value;
				$t = 'n';
				$s = 2;
			}
			else {
				$t = 'inlineStr';

				if (null !== $html) {
					// Break in multiple lines if required
					$html = preg_replace("/[\n\r]/", '', $html);

					$html = preg_replace('/<br[^>]*>/U', "\n", $html);
					$html = strip_tags($html);
					$value = html_entity_decode($html, ENT_QUOTES | ENT_XML1, 'UTF-8');
				}
				else {
					// Break in multiple lines if required
					$value = preg_replace("/[\n\r]/", '', $value);
				}

				$value = trim($value);
			}

			$cell = sprintf('<c r="%s" s="%d" t="%s">', $this->cellName(), $s, $t);

			if ($t !== 'inlineStr') {
				$cell .= '<v>' . htmlspecialchars($value, ENT_XML1) . '</v>';
			}
			else {
				$cell .= '<is>';
			}

			if ($type === 'date') {
				// Calculate cell width from formatted date, not from fake Excel number
				$value = [$date];
			}
			else {
				$value = explode("\n", $value);
			}

			$column_width = 0;

			foreach ($value as $line) {
				if ($colspan === 0 || $colspan === 1) {
					$cell_width = $this->getCellWidth($line, $styles);

					if ($cell_width > $column_width) {
						$column_width = $cell_width;
					}
				}

				if ($t === 'inlineStr') {
					$cell .= sprintf('<r><t xml:space="preserve">%s</t></r>', htmlspecialchars($line, ENT_XML1));
				}
			}

			if ($t === 'inlineStr') {
				$cell .= '</is>';
			}

			$cell .= '</c>';
		}

		$this->rows[$this->row_index][$this->col_index] = $cell;

		if ($colspan > 1 || $rowspan > 1) {
			$this->merged_cells[] = $this->cellName($this->col_index, $this->row_index)
				. ':' . $this->cellName($this->col_index + max($colspan, 1) - 1, $this->row_index + max($rowspan, 1) - 1);
		}

		if ($column_width > ($this->columns_widths[$this->col_index] ?? -1)) {
			$this->columns_widths[$this->col_index] = $column_width;
		}

		// Pre-fill cells for rowspan
		for ($i = 1; $i < $rowspan; $i++) {
			if (!isset($this->rows[$this->row_index + $i])) {
				$this->rows[$this->row_index + $i] = [];
			}

			$this->rows[$this->row_index + $i][$this->col_index] = '';
		}

		// Skip colspan
		for ($i = 1; $i <= $colspan; $i++) {
			//$this->rows[$this->row_index][++$this->col_index] = null;
		}

		$this->col_index += $colspan;
	}

	public function zip(?string $destination = null): ZipWriter
	{
		if (null === $destination) {
			$destination = 'php://output';
		}

		$r = '';
		$s = '';
		$i = 1;

		foreach ($this->sheets as $name => $sheet) {
			// max length of name is 31, let's stop at 30 to be safe, Excel you suck!
			if (mb_strlen($name) > 31) {
				$name = mb_substr($name, 0, 30) . '…';
			}

			$name = htmlspecialchars($name, ENT_XML1);
			$r .= sprintf('<Relationship Id="rId%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet%d.xml"/>', $i + 2, $i);
			$s .= sprintf('<sheet name="%s" sheetId="%d" state="visible" r:id="rId%d"/>', $name, $i, $i + 2);
			$i++;
		}

		/*
		$strings = self::XML_HEADER . '<sst count="39" uniqueCount="36" xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
		$strings .= "\n";

		foreach ($this->strings as $i => $string) {
			$strings .= '<si><t xml:space="preserve">' . htmlspecialchars($string, ENT_XML1) . '</t></si>';
			$strings .= "\n";
		}

		$strings .= '</sst>';

		$this->strings = [];
		*/

		$locale = $this->default_locale;

		if (isset($this->styles['-spreadsheet-locale']) && preg_match('/^[a-z]{2}[_-][A-Z]{2}$', $this->styles['-spreadsheet-locale'])) {
			$locale = $this->styles['-spreadsheet-locale'];
		}

		$locale = substr($locale, 0, 2) . '-' . substr($locale, 3, 2);

		$z = new ZipWriter($destination);
		$z->setCompression(9);
		$z->add('[Content_Types].xml', self::XML_HEADER. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
				<Default ContentType="application/xml" Extension="xml"/>
				<Default ContentType="application/vnd.openxmlformats-package.relationships+xml" Extension="rels"/>
				<Default ContentType="image/png" Extension="png"/>
				<Default ContentType="image/jpeg" Extension="jpeg"/>
				<Override ContentType="application/vnd.openxmlformats-package.relationships+xml" PartName="/_rels/.rels"/>
				<Override ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml" PartName="/xl/workbook.xml"/>
				<Override ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml" PartName="/xl/styles.xml"/>
				<Override ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml" PartName="/xl/worksheets/sheet1.xml"/>
				<Override ContentType="application/vnd.openxmlformats-package.relationships+xml" PartName="/xl/_rels/workbook.xml.rels"/>
				<Override ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml" PartName="/xl/sharedStrings.xml"/>
				<Override ContentType="application/vnd.openxmlformats-package.core-properties+xml" PartName="/docProps/core.xml"/>
				<Override ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml" PartName="/docProps/app.xml"/>
			</Types>');

		$z->add('docProps/app.xml', self::XML_HEADER . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Template></Template><TotalTime>0</TotalTime><Application>KD2.org/TableToXLSX</Application><AppVersion>0.1</AppVersion></Properties>');

		$z->add('docProps/core.xml', self::XML_HEADER . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dcterms:created xsi:type="dcterms:W3CDTF">' . date(DATE_W3C) . '</dcterms:created><dc:creator></dc:creator><dc:description></dc:description><dc:language>' . $locale . '</dc:language><cp:lastModifiedBy></cp:lastModifiedBy><dcterms:modified xsi:type="dcterms:W3CDTF">' . date(DATE_W3C) . '</dcterms:modified><cp:revision>1</cp:revision><dc:subject></dc:subject><dc:title></dc:title></cp:coreProperties>');

		$z->add('_rels/.rels', self::XML_HEADER . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>');

		// Relationships
		$z->add('xl/_rels/workbook.xml.rels', self::XML_HEADER . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>' . $r . '</Relationships>');

		$z->add('xl/workbook.xml', self::XML_HEADER . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><fileVersion appName="Calc"/><workbookPr backupFile="false" showObjects="all" date1904="false"/><workbookProtection/><bookViews><workbookView showHorizontalScroll="true" showVerticalScroll="true" showSheetTabs="true" xWindow="0" yWindow="0" windowWidth="16384" windowHeight="8192" tabRatio="500" firstSheet="0" activeTab="0"/></bookViews><sheets>' . $s . '</sheets><calcPr iterateCount="100" refMode="A1" iterate="false" iterateDelta="0.001"/></workbook>');

		$z->add('xl/sharedStrings.xml', self::XML_HEADER . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"></sst>');
		$z->add('xl/styles.xml', $this->outputStyles());

		$i = 1;

		while ($xml = array_shift($this->sheets)) {
			$z->add(sprintf('xl/worksheets/sheet%d.xml', $i++), $xml);
		}

		$z->finalize();
		return $z;
	}

	public function XML(): string
	{
		return '';
	}

	protected function outputStyles(): string
	{
		return self::XML_HEADER . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
			<numFmts count="1">
				<numFmt numFmtId="164" formatCode="General"/>
				<numFmt numFmtId="165" formatCode="dd/mm/yyyy"/>
				<numFmt numFmtId="166" formatCode="#,##0.00\ [$€-40C];[RED]\-#,##0.00\ [$€-40C]"/>
				<numFmt numFmtId="167" formatCode="0.00\ %"/>
				<numFmt numFmtId="168" formatCode="dd/mm/yyyy\ hh:mm"/>
			</numFmts>
			<fonts count="4">
				<font>
					<sz val="10"/>
					<name val="Arial"/>
					<family val="2"/>
				</font>
				<font>
					<sz val="10"/>
					<name val="Arial"/>
					<family val="0"/>
				</font>
				<font>
					<sz val="10"/>
					<name val="Arial"/>
					<family val="0"/>
				</font>
				<font>
					<sz val="10"/>
					<name val="Arial"/>
					<family val="0"/>
				</font>
			</fonts>
			<fills count="2">
				<fill>
					<patternFill patternType="none"/>
				</fill>
				<fill>
					<patternFill patternType="gray125"/>
				</fill>
			</fills>
			<borders count="1">
				<border diagonalDown="false" diagonalUp="false">
					<left/>
					<right/>
					<top/>
					<bottom/>
					<diagonal/>
				</border>
			</borders>
			<cellStyleXfs count="20">
				<xf applyAlignment="true" applyBorder="true" applyFont="true" applyProtection="true" borderId="0" fillId="0" fontId="0" numFmtId="164"><alignment horizontal="general" indent="0" shrinkToFit="false" textRotation="0" vertical="bottom" wrapText="false"/><protection hidden="false" locked="true"/></xf>
				<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="1" numFmtId="0"/>
				<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="1" numFmtId="0"/>
				<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="2" numFmtId="0"/>
				<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="2" numFmtId="0"/>
				<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="0" numFmtId="0"/>
				<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="0" numFmtId="0"/>
				<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="0" numFmtId="0"/>
				<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="0" numFmtId="0"/>
				<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="0" numFmtId="0"/>
				<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="0" numFmtId="0"/>
				<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="0" numFmtId="0"/>
				<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="0" numFmtId="0"/>
				<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="0" numFmtId="0"/>
				<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="0" numFmtId="0"/>
				<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="1" numFmtId="43"/>
				<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="1" numFmtId="41"/>
				<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="1" numFmtId="44"/>
				<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="1" numFmtId="42"/>
				<xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="1" numFmtId="9"/>
			</cellStyleXfs>
			<cellXfs count="5">
				<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyFont="false" applyBorder="false" applyAlignment="false" applyProtection="false">
					<alignment horizontal="general" vertical="top" textRotation="0" wrapText="false" indent="0" shrinkToFit="false"/>
					<protection locked="true" hidden="false"/>
				</xf>
				<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyFont="false" applyBorder="false" applyAlignment="false" applyProtection="false">
					<alignment horizontal="general" vertical="top" textRotation="0" wrapText="false" indent="0" shrinkToFit="false"/>
					<protection locked="true" hidden="false"/>
				</xf>
				<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyFont="false" applyBorder="false" applyAlignment="false" applyProtection="false">
					<alignment horizontal="general" vertical="top" textRotation="0" wrapText="false" indent="0" shrinkToFit="false"/>
					<protection locked="true" hidden="false"/>
				</xf>
				<xf numFmtId="167" fontId="0" fillId="0" borderId="0" xfId="0" applyFont="false" applyBorder="false" applyAlignment="false" applyProtection="false">
					<alignment horizontal="general" vertical="top" textRotation="0" wrapText="false" indent="0" shrinkToFit="false"/>
					<protection locked="true" hidden="false"/>
				</xf>
				<xf numFmtId="168" fontId="0" fillId="0" borderId="0" xfId="0" applyFont="false" applyBorder="false" applyAlignment="false" applyProtection="false">
					<alignment horizontal="general" vertical="top" textRotation="0" wrapText="false" indent="0" shrinkToFit="false"/>
					<protection locked="true" hidden="false"/>
				</xf>
			</cellXfs>
			<cellStyles count="6">
				<cellStyle builtinId="0" name="Normal" xfId="0"/>
				<cellStyle builtinId="3" name="Comma" xfId="15"/>
				<cellStyle builtinId="6" name="Comma [0]" xfId="16"/>
				<cellStyle builtinId="4" name="Currency" xfId="17"/>
				<cellStyle builtinId="7" name="Currency [0]" xfId="18"/>
				<cellStyle builtinId="5" name="Percent" xfId="19"/>
			</cellStyles>
		</styleSheet>';
	}
}
