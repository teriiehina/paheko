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
 * Very simple ZIP Archive writer
 *
 * for specs see http://www.pkware.com/appnote
 * Inspired by https://github.com/splitbrain/php-archive/blob/master/src/Zip.php
 */
class ZipWriter
{
	protected $compression = 0;
	protected $pos = 0;
	protected $handle;
	protected $directory = [];
	protected $closed = false;

	/**
	 * Create a new ZIP file
	 *
	 * @param string $file
	 * @throws RuntimeException
	 */
	public function __construct($file)
	{
		$this->handle = fopen($file, 'wb');

		if (!$this->handle)
		{
			throw new RuntimeException('Could not open ZIP file for writing: ' . $file);
		}
	}

	/**
	 * Sets compression rate (0 = no compression)
	 *
	 * @param integer $compression 0 to 9
	 * @return void
	 */
	public function setCompression(int $compression): void
	{
		$compression = (int) $compression;
		$this->compression = max(min($compression, 9), 0);
	}

	/**
	 * Write to the current ZIP file
	 * @param string $data
	 * @return void
	 */
	protected function write(string $data): void
	{
		// We can't use fwrite and ftell directly as ftell doesn't work on some pointers
		// (eg. php://output)
		fwrite($this->handle, $data);
		$this->pos += strlen($data);
	}

	/**
	 * Returns the content of the ZIP file
	 *
	 * @return string
	 */
	public function get(): string
	{
		fseek($this->handle, 0);
		return stream_get_contents($this->handle);
	}

	public function __destruct()
	{
		$this->close();
	}

	public function addFromPath(string $file, string $path): void
	{
		$this->add($file, null, $path);
	}

	public function addFromPointer(string $file, $pointer): void
	{
		$this->add($file, null, null, $pointer);
	}


	public function addFromString(string $file, string $data): void
	{
		$this->add($file, $data);
	}


	/**
	 * Add a file to the current Zip archive using the given $data as content
	 *
	 * @param string $file File name
	 * @param string|null $data binary content of the file to add
	 * @param string|null $source Source file to use if no data is supplied
	 * @throws LogicException
	 * @throws RuntimeException
	 */
	public function add(string $file, ?string $data = null, ?string $source = null, $pointer = null): void
	{
		if ($this->closed)
		{
			throw new LogicException('Archive has been closed, files can no longer be added');
		}

		if (null !== $source) {
			$pointer = fopen($source, 'rb');
		}
		elseif (null !== $data) {
			$size = strlen($data);
			$crc  = crc32($data);

			if ($this->compression)
			{
				// Compress data
				$data = gzdeflate($data, $this->compression);
			}

			$csize  = strlen($data);
		}
		elseif (null === $pointer) {
			throw new LogicException('No source file, pointer or data has been supplied');
		}

		$tmp = null;

		try {
			if (null !== $pointer) {
				$tmp = fopen('php://temp', 'wb');
				$size = 0;
				$crc = hash_init('crc32b');
				$filter = null;

				if ($this->compression) {
					$filter = stream_filter_append($tmp,
						'zlib.deflate',
                    	\STREAM_FILTER_WRITE,
                    	['level' => $this->compression]
                    );
				}

				while (!feof($pointer)) {
					$data = fread($pointer, 8192);
					hash_update($crc, $data);
					$size += strlen($data);
					fwrite($tmp, $data);
				}

				$crc = (int) hexdec(hash_final($crc));

				if ($filter) {
					stream_filter_remove($filter);
					$csize = fstat($tmp)['size'];
				}
				else {
					$csize = $size;
				}

				unset($data, $pointer, $gzip);

				if (-1 === fseek($tmp, 0, SEEK_SET) || ftell($tmp) !== 0) {
					throw new \RuntimeException('Cannot seek in temporary stream');
				}
			}

			$offset = $this->pos;

			// write local file header
			$this->write($this->makeRecord(false, $file, $size, $csize, $crc, null));

			// we store no encryption header

			// Store external file
			if ($tmp) {
				$this->pos += stream_copy_to_stream($tmp, $this->handle);
			}
			// Store compressed or uncompressed data
			// that was supplied
			else {
				// write data
				$this->write($data);
			}
		}
		finally {
			if (null !== $tmp) {
				// Always close and delete temporary file
				fclose($tmp);
			}
		}

		// we store no data descriptor

		// add info to central file directory
		$this->directory[] = $this->makeRecord(true, $file, $size, $csize, $crc, $offset);
	}

	/**
	 * Add the closing footer to the archive
	 * @throws LogicException
	 */
	public function finalize(): void
	{
		if ($this->closed)
		{
			throw new LogicException('The ZIP archive has been closed. Files can no longer be added.');
		}

		// write central directory
		$offset = $this->pos;
		$directory = implode('', $this->directory);
		$this->write($directory);

		$end_record = "\x50\x4b\x05\x06" // end of central dir signature
			. "\x00\x00" // number of this disk
			. "\x00\x00" // number of the disk with the start of the central directory
			. pack('v', count($this->directory)) // total number of entries in the central directory on this disk
			. pack('v', count($this->directory)) // total number of entries in the central directory
			. pack('V', strlen($directory)) // size of the central directory
			. pack('V', $offset) // offset of start of central directory with respect to the starting disk number
			. "\x00\x00"; // .ZIP file comment length
		$this->write($end_record);

		$this->directory = [];
		$this->closed = true;
	}

	/**
	 * Close the file handle
	 * @return void
	 */
	public function close(): void
	{
		if (!$this->closed)
		{
			$this->finalize();
		}

		if ($this->handle)
		{
			fclose($this->handle);
		}

		$this->handle = null;
	}

	/**
	 * Creates a record, local or central
	 * @param  boolean $central  TRUE for a central file record, FALSE for a local file header
	 * @param  string  $filename File name
	 * @param  integer $size     File size
	 * @param  integer $compressed_size
	 * @param  string  $crc      CRC32 of the file contents
	 * @param  integer|null  $offset
	 * @return string
	 */
	protected function makeRecord(bool $central, string $filename, int $size, int $compressed_size, string $crc, ?int $offset): string
	{
		$header = ($central ? "\x50\x4b\x01\x02\x0e\x00" : "\x50\x4b\x03\x04");

		list($filename, $extra) = $this->encodeFilename($filename);

		$header .=
			"\x14\x00" // version needed to extract - 2.0
			. "\x00\x08" // general purpose flag - bit 11 set = enable UTF-8 support
			. ($this->compression ? "\x08\x00" : "\x00\x00") // compression method - none
			. "\x01\x80\xe7\x4c" //  last mod file time and date
			. pack('V', $crc) // crc-32
			. pack('V', $compressed_size) // compressed size
			. pack('V', $size) // uncompressed size
			. pack('v', strlen($filename)) // file name length
			. pack('v', strlen($extra)); // extra field length

		if ($central)
		{
			$header .=
				"\x00\x00" // file comment length
				. "\x00\x00" // disk number start
				. "\x00\x00" // internal file attributes
				. "\x00\x00\x00\x00" // external file attributes  @todo was 0x32!?
				. pack('V', $offset); // relative offset of local header
		}

		$header .= $filename;
		$header .= $extra;

		return $header;
	}

	protected function encodeFilename(string $original): array
	{
		// For epub/opendocument files
		if ($original == 'mimetype') {
			return [$original, ''];
		}

		$extra = pack(
			'vvCV',
			0x7075, // tag
			strlen($original) + 5, // length of file + version + crc
			1, // version
			crc32($original) // crc
		);
		$extra .= $original;

		return array($original, $extra);
	}
}
