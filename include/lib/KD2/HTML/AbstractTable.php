<?php

namespace KD2\HTML;

abstract class AbstractTable
{
	abstract public function import(string $html, string $css = null): void;
	abstract public function addTable(iterable $iterator, string $sheet_name = null, array $table_styles = []): void;
	abstract public function addRow(iterable $row, array $row_styles = []): void;

	abstract public function openTable(string $sheet_name, array $styles = []): void;
	abstract public function closeTable(): void;

	abstract public function save(string $filename): void;
	abstract public function output(): void;
	abstract public function fetch(): string;

	public function download(?string $name = null): void
	{
		$name ??= 'document';

		// Sanitize filename
		$name = preg_replace('/[^\w\d\.\h]+/ui', ' ', $name);
		$name = substr($name, 0, 128);

		header('Content-type: ' . static::MIME_TYPE);
		header(sprintf('Content-Disposition: attachment; filename="%s.%s"', $name, static::EXTENSION));
		$this->output();
	}
}
