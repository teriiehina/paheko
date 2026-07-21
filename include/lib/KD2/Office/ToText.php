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

namespace KD2\Office;

use KD2\ZipReader;

/**
 * Converts LibreOffice/MS Office documents to plain text.
 * For OpenDocument files, some kind of formatting will be retained (headlines, lists)
 * Supported input formats: ODT, ODS, ODP, XLSX, DOCX, PPTX.
 *
 * This is based on ideas from ODT2TXT and docx2txt
 * @see https://github.com/dstosberg/odt2txt/blob/master/odt2txt.c
 * @see https://github.com/ankushshah89/python-docx2txt/blob/master/docx2txt/docx2txt.py
 */
class ToText
{
	static public function from(array $source): ?string
	{
		if (isset($source['path'])) {
			return self::fromPath($source['path']);
		}
		elseif (isset($source['pointer'])) {
			return self::fromPointer($source['pointer']);
		}
		elseif (isset($source['string'])) {
			return self::fromString($source['string']);
		}
		elseif (isset($source['content'])) {
			return self::fromString($source['content']);
		}

		return null;
	}

	static public function fromPath(string $file): ?string
	{
		$fp = fopen($file, 'rb');

		try {
			$out = self::fromPointer($fp);
			return $out;
		}
		finally {
			fclose($fp);
		}
	}

	static public function fromPointer($fp): ?string
	{
		fseek($fp, 0, SEEK_SET);
		$header = fread($fp, 4);
		fseek($fp, 0, SEEK_SET);

		if ($header == "PK\003\004") {
			$zip = new ZipReader;
			$zip->setPointer($fp);

			// OpenDocument
			if ($zip->has('mimetype') && ($contents = $zip->fetch('content.xml'))) {
				return self::convertOpenDocument($contents);
			}
			// DOCX/MS Word
			elseif ($contents = $zip->fetch('word/document.xml')) {
				return self::convertDOCX($contents);
			}
			elseif ($contents = $zip->fetch('xl/sharedStrings.xml')) {
				return self::convertXLSX($contents);
			}
			elseif ($zip->has('ppt/slides/slide1.xml')) {
				$contents = '';

				foreach ($zip->iterate() as $name => $file) {
					if (0 === strpos($name, 'ppt/slides/slide') && substr($name, -4) == '.xml') {
						$contents .= $zip->fetch($name);
					}
				}

				return self::convertPPTX($contents);
			}
			else {
				return null;
			}
		}
		elseif ($header == '<?xm') {
			// FODT format (raw)
			$contents = '';

			while (!feof($fp)) {
				$contents .= fgets($fp, 8192);
			}

			return self::fromOpenDocumentFlatXML($contents);
		}

		return null;
	}

	static public function fromOpenDocumentFlatXML(string $contents): string
	{
		if ($pos = strpos($contents, '<office:body>')) {
			$contents = substr($contents, $pos);
		}

		/* remove binary */
		$contents = preg_replace('!<office:binary-data>[^>]*</office:binary-data>!', '', $contents);

		return self::convertOpenDocument($contents);
	}

	static public function fromString(string $str): ?string
	{
		$header = substr($str, 0, 4);

		if ($header == '<?xm') {
			return self::fromOpenDocumentFlatXML($str);
		}
		elseif ($header == "PK\003\004") {
			$fp = fopen('php://temp', 'w');
			fputs($fp, $str);

			try {
				return self::fromPointer($fp);
			}
			finally {
				fclose($fp);
			}
		}
		else {
			return null;
		}
	}

	/**
	 * Converts OpenDocument to plain text
	 * This is mostly a PHP port from ODT2TXT, but better
	 * @see https://github.com/dstosberg/odt2txt/blob/master/odt2txt.c
	 */
	static public function convertOpenDocument(string $contents): string
	{
		/* remove soft-page-breaks. We don't need them and they may disturb later decoding */
		$contents = str_replace('<text:soft-page-break/>', '', $contents);
		/* same for xml-protected spaces */
		$contents = str_replace('<text:s/>', ' ', $contents);

		/* headline, first level */
		$contents = preg_replace_callback('!<text:h[^>]*outline-level="(\d+)"[^>]*>([^<]*)<[^>]*>!',
			fn ($m) => sprintf("%s %s\n\n", str_repeat('#', $m[1]), $m[2]),
			$contents);

		/* other headlines */
		$contents = preg_replace('!<text:h[^>]*>([^<]*)<[^>]*>!', '## $1', $contents);

		// List items
		$contents = preg_replace("!<text:list-item[^>]*>\s*<text:p[^>]*>!", '* ', $contents);

		/* normal paragraphs */
		$contents = preg_replace('!<text:p [^>]*>|</text:p>!', "\n\n", $contents);

		/* tabs */
		$contents = str_replace('<text:tab/>', "\t", $contents);
		$contents = str_replace('<text:line-break/>', "\n", $contents);

		/* images */
		$contents = preg_replace_callback('!<draw:frame[^>]*draw:name=\"([^\"]*)\"[^>]*>!',
			fn($m) => sprintf('[-- Image: %s --]', $m[1]),
			$contents);

		$contents = self::cleanXML($contents);

		/* remove indentations, e.g. kword */
		$contents = preg_replace("!\n +!", "\n", $contents);
		/* remove large vertical spaces */
		$contents = preg_replace("!\n{3,}!", "\n\n", $contents);

		$contents = trim($contents);

		return $contents;
	}

	static public function convertDOCX(string $contents): string
	{
		// Remove all line breaks
		$contents = str_replace(["\n", "\r"], '', $contents);

		// Paragraph breaks
		$contents = str_replace('</w:p>', "\n\n", $contents);
		// Line breaks
		$contents = preg_replace('!<w:br[^>]*>!', "\n", $contents);

		return self::cleanXML($contents);
	}

	static public function convertXLSX(string $contents): string
	{
		// Remove all line breaks
		$contents = str_replace(["\n", "\r"], '', $contents);
		$contents = str_replace('</t>', "\n", $contents);

		return self::cleanXML($contents);
	}

	static public function convertPPTX(string $contents): string
	{
		// Remove all line breaks
		$contents = str_replace(["\n", "\r"], '', $contents);
		$contents = str_replace('</a:t>', "\n", $contents);

		return self::cleanXML($contents);
	}

	static protected function cleanXML(string $contents): string
	{
		// Remove tags
		$contents = preg_replace('!<[^>]*>!', '', $contents);

		$contents = html_entity_decode($contents, ENT_QUOTES | ENT_XHTML, 'UTF-8');
		$contents = trim($contents);

		return $contents;
	}
}
