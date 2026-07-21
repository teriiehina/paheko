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

namespace KD2;

use LogicException;
use RuntimeException;

/**
 * Very simple ZIP Archive reader
 *
 * for specs see http://www.pkware.com/appnote
 * @see https://github.com/splitbrain/php-archive/blob/460c20518033e8478d425c48e7bb0bd348b10486/src/Zip.php
 * @see https://github.com/ronomon/zip
 * @see https://www.bamsoftware.com/hacks/zipbomb/
 */
class ZipReader
{
	protected $fp = null;
	protected ?array $entries;
	protected bool $close = false;
	protected ?int $file_size = null;

	/**
	 * Max. allowed uncompressed size: 5 GB
	 * @var int
	 */
	protected int $max_size = 1024*1024*1024*5;

	/**
	 * Max. allowed number of files
	 * @var integer
	 */
	protected int $max_files = 50000;

	/**
	 * Max. allowed levels of subdirectories
	 */
	protected int $max_levels = 10;

	protected bool $security_check = true;

	public function setPointer($fp)
	{
		$this->fp = $fp;
		$this->entries = null;
		$this->file_size = null;

		if (!isset($this->file_size)
			&& ($meta = stream_get_meta_data($fp))
			&& !empty($meta['seekable'])) {
			fseek($fp, 0, SEEK_END);
			$this->file_size = ftell($fp);
			fseek($fp, 0);
		}

		if ($this->security_check) {
			$this->securityCheck();
		}
	}

	public function open(string $file)
	{
		if (!is_readable($file)) {
			throw new \InvalidArgumentException('Could not open ZIP file for reading: ' . $file);
		}

		$this->setPointer(fopen($file, 'rb'));
		$this->close = true;
	}

	public function setMaxUncompressedSize(int $size): void
	{
		$this->max_size = $size;
	}

	public function setMaxFiles(int $files): void
	{
		$this->max_files = $files;
	}

	public function setMaxDirectoryLevels(int $levels): void
	{
		$this->max_levels = $levels;
	}

	public function enableSecurityCheck(bool $enable): void
	{
		$this->security_check = $enable;
	}

	public function securityCheck(): void
	{
		$size = 0;
		$files = 0;
		$levels = 0;

		foreach ($this->iterate() as $file) {
			$size += $file['size'];
			$files++;
			$levels = max($levels, substr_count($file['filename'], '/'));

			if ($size > $this->max_size) {
				throw new \OutOfBoundsException(sprintf('Uncompressed size is larger than max. allowed (%d bytes).', $this->max_size));
			}

			if ($files > $this->max_files) {
				throw new \OutOfBoundsException(sprintf('The archive contains more files than allowed (max. %d files).', $this->max_files));
			}

			if ($levels > $this->max_levels) {
				throw new \OutOfBoundsException(sprintf('The archive contains more levels of subdirectories than allowed (max. %d levels).', $this->max_levels));
			}

			if (false !== strpos($file['filename'], '..')) {
				throw new \OutOfBoundsException('Invalid filename in archive: ' . $file['filename']);
			}
		}

		// Suspicious uncompressed size
		if (isset($this->file_size) && $size >= $this->file_size * 100) {
			throw new \OutOfBoundsException('The archive uncompressed size is more than 100 times the compressed size.');
		}
	}

	public function iterate(): \Generator
	{
		if (isset($this->entries)) {
			yield from $this->entries;
			return;
		}

		$centd = $this->readCentralDir();

		@rewind($this->fp);
		@fseek($this->fp, $centd['offset']);

		for ($i = 0; $i < $centd['entries']; $i++) {
			$header = $this->readCentralFileHeader();

			$prev_pos = ftell($this->fp);
			fseek($this->fp, $header['offset']);

			// Use local file header
			$header = $this->readFileHeader($header);
			$header['start'] = ftell($this->fp);

			fseek($this->fp, $prev_pos);

			$name = $header['extra']['utf8path'] ?? $header['filename'];
			$name = str_replace('\\', '/', $name);

			$this->entries[$name] = $header;
			yield $name => $header;
		}
	}

	public function has(string $file): bool
	{
		$this->_load();

		return array_key_exists($file, $this->entries);
	}

	public function fetch(string $path): ?string
	{
		$this->_load();

		if (!array_key_exists($path, $this->entries)) {
			return null;
		}

		return $this->extract($this->entries[$path]);
	}

	/**
	 * Extract the whole archive to a a directory, or a specific file to a path
	 */
	public function extractTo(string $destination, ?string $path = null): int
	{
		if (null !== $path) {
			$this->_load();

			if (!isset($this->entries[$path])) {
				return 0;
			}

			$this->extract($this->entries[$path], $destination);

			return 1;
		}

		$count = 0;

		foreach ($this->iterate() as $file) {
			$dest = $destination . str_replace('/', DIRECTORY_SEPARATOR, $file['filename']);
			$this->extract($file, $dest);
			$count++;
		}

		return $count;
	}

	/**
	 * Extract a file into a file pointer resource
	 */
	public function extractToPointer($pointer, string $path): bool
	{
		$this->_load();

		if (!isset($this->entries[$path])) {
			return false;
		}

		$this->extract($this->entries[$path], $pointer);
		return true;
	}

	/**
	 * Return the total uncompressed size of all files in the archive
	 */
	public function uncompressedSize(): int
	{
		$size = 0;

		foreach ($this->iterate() as $name => $file) {
			$size += $file['size'];
		}

		return $size;
	}

	protected function _load(): void
	{
		if (isset($this->entries)) {
			return;
		}

		foreach ($this->iterate() as $file) {
			// Just load
		}
	}

	public function extract(array $header, $destination = null): ?string
	{
		fseek($this->fp, $header['start']);

		$is_file = false;

		if (is_string($destination)) {
			$is_file = true;
			$destination = fopen($destination, 'wb');
		}
		elseif (null !== $destination && !is_resource($destination)) {
			throw new \InvalidArgumentException('Only a file pointer or a string can be specified');
		}
		elseif (null === $destination) {
			$str = '';
		}

		if ($header['compression'] != 0) {
			// hack, see https://groups.google.com/forum/#!topic/alt.comp.lang.php/37_JZeW63uc
			$pos = ftell($this->fp);
			rewind($this->fp);
			fseek($this->fp, $pos);

			$filter = stream_filter_append($this->fp, 'zlib.inflate', \STREAM_FILTER_READ);
		}

		$limit = $header['size'];
		$offset = 0;

		while ($offset < $limit) {
			$length = min(8192, $limit - $offset);

			try {
				$buffer = fread($this->fp, $length);
			}
			catch (\Throwable $e) {
				if (false !== strpos($e->getMessage(), 'zlib: data error')) {
					throw new \RuntimeException(sprintf('Invalid compressed data for entry "%s".', $header['filename']), 0, $e);
				}

				throw $e;
			}

			if ($buffer === false) {
				throw new \RuntimeException(sprintf('Error reading the contents of entry "%s".', $header['filename']));
			}

			if ($destination) {
				fwrite($destination, $buffer);
			}
			else {
				$str .= $buffer;
			}

			$offset += $length;
		}

		if ($header['compression'] != 0) {
			stream_filter_remove($filter);
		}

		if ($is_file) {
			fclose($destination);
		}

		if ($destination) {
			return null;
		}
		else {
			return $str;
		}
	}

	protected function readCentralFileHeader(): array
	{
		$binary_data = fread($this->fp, 46);
		$header      = unpack(
			'vchkid/vid/vversion/vversion_extracted/vflag/vcompression/vmtime/vmdate/Vcrc/Vcompressed_size/Vsize/vfilename_len/vextra_len/vcomment_len/vdisk/vinternal/Vexternal/Voffset',
			$binary_data
		);

		if ($header['size'] == 0xffffffff) {
			throw new \OutOfBoundsException('ZIP64 files are not supported');
		}

		if ($header['filename_len'] != 0) {
			$header['filename'] = fread($this->fp, $header['filename_len']);
		} else {
			$header['filename'] = '';
		}

		if ($header['extra_len'] != 0) {
			$header['extra'] = fread($this->fp, $header['extra_len']);
			$header['extradata'] = $this->parseExtra($header['extra']);
		} else {
			$header['extra'] = '';
			$header['extradata'] = [];
		}

		if ($header['comment_len'] != 0) {
			$header['comment'] = fread($this->fp, $header['comment_len']);
		} else {
			$header['comment'] = '';
		}

		$header['mtime'] = $this->makeUnixTime($header['mdate'], $header['mtime']);

		if (substr($header['filename'], -1) == '/') {
			$header['external'] = 0x41FF0010;
		}

		$header['dir'] = ($header['external'] == 0x41FF0010 || $header['external'] == 16) ? 1 : 0;
		return $header;
	}

	protected function readFileHeader(array $header)
	{
		$binary_data = fread($this->fp, 30);
		$data        = unpack(
			'vchk/vid/vversion/vflag/vcompression/vmtime/vmdate/Vcrc/Vcompressed_size/Vsize/vfilename_len/vextra_len',
			$binary_data
		);

		if ($header['size'] == 0xffffffff) {
			throw new \OutOfBoundsException('ZIP64 files are not supported');
		}

		$header['filename'] = fread($this->fp, $data['filename_len']);

		if ($data['extra_len'] != 0) {
			$header['extra'] = fread($this->fp, $data['extra_len']);
			$header['extradata'] = array_merge($header['extradata'],  $this->parseExtra($header['extra']));
		} else {
			$header['extra'] = '';
			$header['extradata'] = array();
		}

		$header['compression'] = $data['compression'];

		// On ODT files, these headers are 0 (streamed). Keep the value from central file header.
		foreach (['size', 'compressed_size', 'crc'] as $hd) {
			if ($header[$hd] === 0) {
				$header[$hd] = $data[$hd];
			}
		}

		$header['flag']  = $data['flag'];
		$header['mtime'] = $this->makeUnixTime($data['mdate'], $data['mtime']);
		$header['folder'] = ($header['external'] == 0x41FF0010 || $header['external'] == 16) ? 1 : 0;
		return $header;
	}

	protected function parseExtra($header)
	{
		$extra = [];

		// parse all extra fields as raw values
		while (strlen($header) !== 0) {
			$set = unpack('vid/vlen', $header);
			$header = substr($header, 4);
			$value = substr($header, 0, $set['len']);
			$header = substr($header, $set['len']);
			$extra[$set['id']] = $value;
		}

		// handle known ones
		if(isset($extra[0x6375])) {
			$extra['utf8comment'] = substr($extra[0x6375], 5); // strip version and crc
			unset($extra[0x6375]);
		}

		if(isset($extra[0x7075])) {
			$extra['utf8path'] = substr($extra[0x7075], 5); // strip version and crc
			unset($extra[0x7075]);
		}

		return $extra;
	}

	protected function cpToUtf8($string)
	{
		if (function_exists('iconv') && @iconv_strlen('', 'CP437') !== false) {
			return iconv('CP437', 'UTF-8', $string);
		} elseif (function_exists('mb_convert_encoding')) {
			return mb_convert_encoding($string, 'UTF-8', 'CP850');
		} else {
			return $string;
		}
	}

	protected function makeUnixTime($mdate = null, $mtime = null)
	{
		if ($mdate && $mtime) {
			$year = (($mdate & 0xFE00) >> 9) + 1980;
			$month = ($mdate & 0x01E0) >> 5;
			$day = $mdate & 0x001F;

			$hour = ($mtime & 0xF800) >> 11;
			$minute = ($mtime & 0x07E0) >> 5;
			$seconde = ($mtime & 0x001F) << 1;

			return mktime($hour, $minute, $seconde, $month, $day, $year);
		}
		else {
			return null;
		}
	}

	protected function readCentralDir()
	{
		rewind($this->fp);

		if (fread($this->fp, 4) !== "PK\x03\x04") {
			throw new \InvalidArgumentException('Invalid archive: is not a zip file');
		}

		fseek($this->fp, 0, SEEK_END);
		$size = ftell($this->fp);
		rewind($this->fp);

		if ($size < 277) {
			$maximum_size = $size;
		} else {
			$maximum_size = 277;
		}

		@fseek($this->fp, $size - $maximum_size);
		$pos   = ftell($this->fp);
		$bytes = 0x00000000;

		while ($pos < $size) {
			$byte  = @fread($this->fp, 1);
			$bytes = (($bytes << 8) & 0xFFFFFFFF) | ord($byte);
			if ($bytes == 0x504b0506) {
				break;
			}
			$pos++;
		}

		$data = @unpack(
			'vdisk/vdisk_start/vdisk_entries/ventries/Vsize/Voffset/vcomment_size',
			fread($this->fp, 18)
		);

		if (empty($data)) {
			throw new \InvalidArgumentException('Invalid archive: corrupt central dir at position ' . ftell($this->fp));
		}

		if ($data['comment_size'] != 0) {
			$data['comment'] = fread($this->fp, $data['comment_size']);
		}

		return $data;
	}

	public function __destruct()
	{
		if ($this->fp && $this->close) {
			@fclose($this->fp);
		}
	}
}
