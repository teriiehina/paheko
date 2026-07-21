<?php

namespace KD2\HTML;

use KD2\HTML\TableToCSV;
use KD2\HTML\TableToODS;
use KD2\HTML\TableToXLSX;

class TableExport
{
	static public function download(string $format, string $name, string $html, string $css): void
	{
		if ('ods' === $format) {
			self::ODS($html, $css, $name)->download($name);
		}
		elseif ('xlsx' === $format) {
			self::XLSX($html, $css, $name)->download($name);
		}
		elseif ('csv' === $format) {
			self::CSV($html)->download($name);
		}
		else {
			throw new \InvalidArgumentException('Invalid format: ' . $format);
		}
	}

	static public function ODS(string $html, string $css, ?string $title = null): TableToODS
	{
		$ods = new TableToODS;

		if (isset($title)) {
			$ods->default_sheet_name = $title;
		}

		$ods->import($html, $css);
		return $ods;
	}

	static public function toODS(string $output, string $html, string $css, ?string $title = null): void
	{
		self::ods($html, $css, $title)->save($output);
	}

	static public function XLSX(string $html, string $css, ?string $title = null): TableToXLSX
	{
		$x = new TableToXLSX;

		if (isset($title)) {
			$x->default_sheet_name = $title;
		}

		$x->import($html, $css);
		return $x;
	}

	static public function toXLSX(string $output, string $html, string $css, ?string $title = null): void
	{
		self::XLSX($html, $css, $title)->save($output);
	}

	static public function CSV(string $html): TableToCSV
	{
		$csv = new TableToCSV;
		$csv->import($html);
		return $csv;
	}

	static public function toCSV(string $output, string $html): void
	{
		self::CSV($html)->save($output);
	}
}