<?php
/*
    This file is part of KD2FW -- <http://dev.kd2.org/>

    Copyright (c) 2001-2019 BohwaZ <http://bohwaz.net/>
    All rights reserved.

    KD2FW is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    Foobar is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.

    You should have received a copy of the GNU Affero General Public License
    along with Foobar.  If not, see <https://www.gnu.org/licenses/>.
*/

namespace KD2\Office\Calc;

use KD2\ZipWriter;

/**
 * OpenDocument Spreadsheet exporter (very simple)
 */
class Writer
{
	const DEFAULT_TABLE_NAME = 'Sheet1';
	const XML_HEADER = '<?xml version="1.0" encoding="UTF-8"?>';
	const LINE_HEIGHT = '0.42';
	const SINGLE_LINE_HEIGHT = '0.452';
	const COLUMN_CHARACTER_WIDTH = '0.15';

	public $table_name = 'Sheet1';

	protected $rows = [];
	protected $rows_height = [];
	protected $columns_width = [];

	public function add(array $columns)
	{
		if (count($this->rows) && count(end($this->rows)) != count($columns))
		{
			throw new \LogicException('Mismatching column count');
		}

		// Remove named keys
		$columns = array_values($columns);

		$height = 1;

		// Try to find the tallest cell
		foreach ($columns as $column_index => $cell)
		{
			if (is_object($cell) && ($cell instanceof \DateTimeInterface)) {
				$cell = $cell->format('Y-m-d');
			}
			elseif (!is_string($cell) && !is_int($cell) && !is_float($cell)) {
				continue;
			}

			$cell = trim($cell);
			$lines = explode("\n", $cell);
			$height = max($height, count($lines));

			// Calculate maximum row width
			$max_line_length = max(array_map('strlen', $lines)) + 2;

			if (!isset($this->columns_width[$column_index]))
			{
				$this->columns_width[$column_index] = 1;
			}

			$this->columns_width[$column_index] = max($this->columns_width[$column_index], $max_line_length);
		}

		// Append data to table
		$this->rows[] = $columns;

		$this->rows_height[] = $height;
	}

	protected function toXML()
	{
		$out = self::XML_HEADER . '<office:document-content office:version="1.2" xmlns:calcext="urn:org:documentfoundation:names:experimental:calc:xmlns:calcext:1.0" xmlns:chart="urn:oasis:names:tc:opendocument:xmlns:chart:1.0" xmlns:css3t="http://www.w3.org/TR/css3-text/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dom="http://www.w3.org/2001/xml-events" xmlns:dr3d="urn:oasis:names:tc:opendocument:xmlns:dr3d:1.0" xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" xmlns:drawooo="http://openoffice.org/2010/draw" xmlns:field="urn:openoffice:names:experimental:ooo-ms-interop:xmlns:field:1.0" xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" xmlns:form="urn:oasis:names:tc:opendocument:xmlns:form:1.0" xmlns:formx="urn:openoffice:names:experimental:ooxml-odf-interop:xmlns:form:1.0" xmlns:grddl="http://www.w3.org/2003/g/data-view#" xmlns:loext="urn:org:documentfoundation:names:experimental:office:xmlns:loext:1.0" xmlns:math="http://www.w3.org/1998/Math/MathML" xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" xmlns:number="urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0" xmlns:of="urn:oasis:names:tc:opendocument:xmlns:of:1.2" xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:ooo="http://openoffice.org/2004/office" xmlns:oooc="http://openoffice.org/2004/calc" xmlns:ooow="http://openoffice.org/2004/writer" xmlns:presentation="urn:oasis:names:tc:opendocument:xmlns:presentation:1.0" xmlns:rpt="http://openoffice.org/2005/report" xmlns:script="urn:oasis:names:tc:opendocument:xmlns:script:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:tableooo="http://openoffice.org/2009/table" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:xforms="http://www.w3.org/2002/xforms" xmlns:xhtml="http://www.w3.org/1999/xhtml" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
		<office:automatic-styles>
			<style:style style:family="table" style:name="ta1">
				<style:table-properties style:writing-mode="lr-tb" table:display="true" />
			</style:style>
			<number:date-style number:automatic-order="true" style:name="N37">
				<number:day number:style="long"/>
				<number:text>/</number:text>
				<number:month number:style="long"/>
				<number:text>/</number:text>
				<number:year number:style="long"/>
			</number:date-style>
			<style:style style:data-style-name="N37" style:family="table-cell" style:name="ce1" style:parent-style-name="Default"/>
			<style:style style:family="table-column" style:name="co-default">
				<style:table-column-properties fo:break-before="auto" style:use-optimal-column-width="true"/>
			</style:style>';

		foreach ($this->columns_width as $index => $width)
		{
			$out .= sprintf('<style:style style:family="table-column" style:name="co%s"><style:table-column-properties fo:break-before="auto" style:use-optimal-column-width="true" style:column-width="%scm"/></style:style>',
				$index, $width * self::COLUMN_CHARACTER_WIDTH);
		}

		$heights = array_unique($this->rows_height);

		// Create styles for manual row heights because LibreOffice has a bug
		// and doesn't take in account "use-optimal-row-height"
		// see https://bugs.documentfoundation.org/show_bug.cgi?id=62268
		foreach ($heights as $height_index)
		{
			$height_cm = $height_index == 1 ? self::SINGLE_LINE_HEIGHT : $height_index * self::LINE_HEIGHT;
			$out .= sprintf('<style:style style:family="table-row" style:name="ro%s"><style:table-row-properties fo:break-before="auto" style:row-height="%scm" style:use-optimal-row-height="true" /></style:style>',
				$height_index, $height_cm);
		}

		$out .= sprintf('</office:automatic-styles><office:body><office:spreadsheet><table:table table:name="%s" table:style-name="ta1">', 
			htmlspecialchars($this->table_name, ENT_XML1, 'UTF-8'));

		$nb_columns = isset($this->rows[0]) ? count($this->rows[0]) : 0;

		for ($i = 0; $i < $nb_columns; $i++)
		{
			$out .= sprintf('<table:table-column table:style-name="co%s" />', isset($this->columns_width[$i]) ? $i : '-default');
		}

		foreach ($this->rows as $i => $row)
		{
			$out .= sprintf('<table:table-row table:style-name="ro%s">', $this->rows_height[$i]);

			foreach ($row as $column)
			{
				if (is_object($column) && $column instanceof \DateTimeInterface)
				{
					$format = !intval($column->format('His')) ? 'Y-m-d' : 'Y-m-d\TH:i:s';
					$params = sprintf('calcext:value-type="date" office:date-value="%s" office:value-type="date" table:style-name="ce1"', $column->format($format));
					$column = $column->format($format);
				}
				elseif (is_int($column) || is_float($column) || (preg_match('/^-?\d*[,.]\d+$|^-?((?!0)\d+)$/', (string) $column)))
				{
					$params = sprintf('calcext:value-type="float" office:value="%f" office:value-type="float"', str_replace(',', '.', $column));
				}
				else
				{
					$params = 'calcext:value-type="string" office:value-type="string"';
				}

				$out .= '<table:table-cell ' . $params . '>';

				$column = explode("\n", (string) $column);

				foreach ($column as $line)
				{
						$out .= sprintf('<text:p>%s</text:p>', htmlspecialchars($line, ENT_XML1, 'UTF-8'));
				}

				$out .= '</table:table-cell>';
			}

			$out .= '</table:table-row>';
		}

		$out .= '</table:table></office:spreadsheet></office:body></office:document-content>';

		return $out;
	}

	protected function zip($file)
	{
		$z = new ZipWriter($file);
		$z->add('mimetype', 'application/vnd.oasis.opendocument.spreadsheet');
		$z->setCompression(9);
		$z->add('settings.xml', self::XML_HEADER . '<office:document-settings office:version="1.2" xmlns:config="urn:oasis:names:tc:opendocument:xmlns:config:1.0" xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:ooo="http://openoffice.org/2004/office" xmlns:xlink="http://www.w3.org/1999/xlink"></office:document-settings>');
		$z->add('content.xml', $this->toXML());
		$z->add('meta.xml', self::XML_HEADER . '<office:document-meta office:version="1.2" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:grddl="http://www.w3.org/2003/g/data-view#" xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:ooo="http://openoffice.org/2004/office" xmlns:xlink="http://www.w3.org/1999/xlink"></office:document-meta>');
		$z->add('styles.xml', self::XML_HEADER . '<office:document-styles office:version="1.2" xmlns:calcext="urn:org:documentfoundation:names:experimental:calc:xmlns:calcext:1.0" xmlns:chart="urn:oasis:names:tc:opendocument:xmlns:chart:1.0" xmlns:css3t="http://www.w3.org/TR/css3-text/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dom="http://www.w3.org/2001/xml-events" xmlns:dr3d="urn:oasis:names:tc:opendocument:xmlns:dr3d:1.0" xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" xmlns:drawooo="http://openoffice.org/2010/draw" xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" xmlns:form="urn:oasis:names:tc:opendocument:xmlns:form:1.0" xmlns:grddl="http://www.w3.org/2003/g/data-view#" xmlns:loext="urn:org:documentfoundation:names:experimental:office:xmlns:loext:1.0" xmlns:math="http://www.w3.org/1998/Math/MathML" xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" xmlns:number="urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0" xmlns:of="urn:oasis:names:tc:opendocument:xmlns:of:1.2" xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:ooo="http://openoffice.org/2004/office" xmlns:oooc="http://openoffice.org/2004/calc" xmlns:ooow="http://openoffice.org/2004/writer" xmlns:presentation="urn:oasis:names:tc:opendocument:xmlns:presentation:1.0" xmlns:rpt="http://openoffice.org/2005/report" xmlns:script="urn:oasis:names:tc:opendocument:xmlns:script:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:tableooo="http://openoffice.org/2009/table" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:xhtml="http://www.w3.org/1999/xhtml" xmlns:xlink="http://www.w3.org/1999/xlink"></office:document-styles>');
		$z->add('manifest.rdf', self::XML_HEADER . '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><rdf:Description rdf:about="styles.xml"><rdf:type rdf:resource="http://docs.oasis-open.org/ns/office/1.2/meta/odf#StylesFile"/></rdf:Description><rdf:Description rdf:about=""><ns0:hasPart xmlns:ns0="http://docs.oasis-open.org/ns/office/1.2/meta/pkg#" rdf:resource="styles.xml"/></rdf:Description><rdf:Description rdf:about="content.xml"><rdf:type rdf:resource="http://docs.oasis-open.org/ns/office/1.2/meta/odf#ContentFile"/></rdf:Description><rdf:Description rdf:about=""><ns0:hasPart xmlns:ns0="http://docs.oasis-open.org/ns/office/1.2/meta/pkg#" rdf:resource="content.xml"/></rdf:Description><rdf:Description rdf:about=""><rdf:type rdf:resource="http://docs.oasis-open.org/ns/office/1.2/meta/pkg#Document"/></rdf:Description></rdf:RDF>');
		$z->add('META-INF/manifest.xml', self::XML_HEADER . '
			<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.2">
				<manifest:file-entry manifest:full-path="/" manifest:version="1.2" manifest:media-type="application/vnd.oasis.opendocument.spreadsheet"/>
				<manifest:file-entry manifest:full-path="settings.xml" manifest:media-type="text/xml"/>
				<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>
				<manifest:file-entry manifest:full-path="meta.xml" manifest:media-type="text/xml"/>
				<manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/>
				<manifest:file-entry manifest:full-path="manifest.rdf" manifest:media-type="application/rdf+xml"/>
				<manifest:file-entry manifest:full-path="Configurations2/accelerator/current.xml" manifest:media-type=""/>
				<manifest:file-entry manifest:full-path="Configurations2/" manifest:media-type="application/vnd.sun.xml.ui.configuration"/>
			</manifest:manifest>');
		$z->finalize();
		return $z;
	}

	public function output($file = null)
	{
		$this->zip($file ?: 'php://output')->close();
	}

	public function get()
	{
		$zip = $this->zip('php://temp');
		$data = $zip->get();
		$zip->close();
		return $data;
	}
}