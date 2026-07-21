<?php

namespace KD2\HTML;

use DOMDocument;
use DOMNode;
use DOMXPath;

/**
 * Converts the first HTML table of a document to CSV
 *
 * - only the first table is handled
 * - colspan is supported
 * - rowspan is *NOT* supported
 *
 * Usage: $csv = new TableToCSV; $csv->import('<table...</table>'); $csv->save('file.csv');
 *
 * @author bohwaz <https://bohwaz.net/>
 */
class TableToCSV extends AbstractTable
{
	const MIME_TYPE = 'application/csv';
	const EXTENSION = 'csv';

	protected array $rows = [];
	protected string $separator = ',';
	protected string $quote = '"';
	protected string $short_date_format = 'Y-m-d';
	protected string $long_date_format = 'Y-m-d H:i:s';

	public function addTable(iterable $iterator, string $sheet_name = null, array $table_styles = []): void
	{
		foreach ($iterator as $row) {
			$this->addRow($row);
		}
	}

	public function openTable(string $sheet_name, array $styles = []): void
	{
	}

	public function closeTable(): void
	{
	}

	public function addRow(iterable $row, array $row_styles = []): void
	{
		$c = 0;
		$r = count($this->rows);
		$this->rows[$r] = [];

		foreach ($row as $cell) {
			$this->rows[$r][$c++] = $this->getCellValue($cell);
		}
	}

	public function setShortDateFormat(string $format): void
	{
		$this->short_date_format = $format;
	}

	public function setLongDateFormat(string $format): void
	{
		$this->long_date_format = $format;
	}

	public function setSeparator(string $separator): void
	{
		$this->separator = $separator;
	}

	public function setQuote(string $quote): void
	{
		$this->quote = $quote;
	}

	public function import(string $html, ?string $css = null): void
	{
		libxml_use_internal_errors(true);

		if (!stristr($html, '<body')) {
			$html = '<body>' . $html . '</body>';
		}

		$doc = new DOMDocument;
		$doc->loadHTML('<meta charset="utf-8" />' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

		$this->rows = [];

		foreach ($this->xpath($doc, './/table') as $i => $table) {
			$this->importTable($table);
			break; // We only support the first table currently
		}

		unset($doc);
	}

	public function xpath(DOMNode $dom, string $query, int $item = null)
	{
		$xpath = new DOMXPath($dom instanceOf DOMDocument ? $dom : $dom->ownerDocument);
		$result = $xpath->query($query, $dom);

		if (null !== $item) {
			if (!$result->length || $result->length < $item + 1) {
				return null;
			}

			return $result->item($item);
		}

		return $result;
	}

	protected function getCellValue($value, ?DOMNode $cell = null): string
	{
		if (!is_string($value) && $value instanceof \DateTimeInterface) {
			$value = $value->format($value->format('His') === '000000' ? $this->short_date_format : $this->long_date_format);
		}

		// Remove space and non-breaking space from number value
		if ($cell && $cell->hasAttribute('data-spreadsheet-value')) {
			$number_value = $cell->getAttribute('data-spreadsheet-value');
		}
		else {
			$number_value = $value ?? '';
		}

		$number_value = str_replace([' ', "\xC2\xA0"], '', $number_value);
		$is_number = $cell && $cell->getAttribute('data-spreadsheet-type') === 'number';

		if ($is_number || preg_match('/^-?\d+(?:[,.]\d+)?$/', $number_value)) {
			$value = $number_value;
		}

		// Escape value
		$value = str_replace("\r\n", "\n", (string) $value);

		if ($this->quote === '') {
			$value = str_replace($this->separator, '', $value);
		}
		else {
			$value = str_replace($this->quote, $this->quote . $this->quote, $value);
		}

		$value = $this->quote . $value . $this->quote;
		return $value;
	}

	protected function importTable(DOMNode $table): void
	{
		$row_index = 0;

		foreach ($this->xpath($table, './/tr') as $row) {
			$col_index = 0;
			$cells = $this->xpath($row, './/td|.//th');

			foreach ($cells as $cell) {
				// Skip rowspan
				while (isset($this->rows[$row_index][$col_index])) {
					$col_index++;
				}

				$value = $cell->textContent;
				$value = html_entity_decode($value, ENT_QUOTES | ENT_XML1);
				$value = trim($value);
				$value = $this->getCellValue($value, $cell);

				$this->rows[$row_index][$col_index++] = $value;

				$colspan = intval($cell->getAttribute('colspan') ?: 1);

				if ($colspan > 1) {
					for ($i = 1; $i < $colspan; $i++) {
						$this->rows[$row_index][$col_index++] = '';
					}
				}

				$rowspan = intval($cell->getAttribute('rowspan') ?: 1);

				if ($rowspan > 1) {
					// Pre-fill cells for rowspan
					for ($i = 1; $i < $rowspan; $i++) {
						if (!isset($this->rows[$row_index + $i])) {
							$this->rows[$row_index + $i] = [];
						}

						$this->rows[$row_index + $i][$col_index] = '';
					}
				}
			}

			$row_index++;
		}
	}

	public function save(string $filename): void
	{
		file_put_contents($filename, $this->fetch());
	}

	public function fetch(): string
	{
		$csv = '';

		foreach ($this->rows as $row) {
			$csv .= implode($this->separator, $row) . "\r\n";
		}

		return $csv;
	}

	public function output(): void
	{
		$this->save('php://output');
	}
}
