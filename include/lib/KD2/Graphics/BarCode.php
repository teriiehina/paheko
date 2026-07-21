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

namespace KD2\Graphics;

/**
 * Generates an EAN-13 barcode
 */
class BarCode
{
	const PRITY = [
		[1,1,1,1,1,1],
		[1,1,0,1,0,0],
		[1,1,0,0,1,0],
		[1,1,0,0,0,1],
		[1,0,1,1,0,0],
		[1,0,0,1,1,0],
		[1,0,0,0,1,1],
		[1,0,1,0,1,0],
		[1,0,1,0,0,1],
		[1,0,0,1,0,1]
		];

	const BARTABLE = [
		['3211','1123'],
		['2221','1222'],
		['2122','2212'],
		['1411','1141'],
		['1132','2311'],
		['1231','1321'],
		['1114','4111'],
		['1312','2131'],
		['1213','3121'],
		['3112','2113']
	];

	protected string $code;

	public function __construct(string $code)
	{
		$this->code = preg_replace('/[^\d]/', '', $code);
	}

	public function get(): string
	{
		return $this->code;
	}

	public function verify(): bool
	{
		if (strlen($this->code) < 13) {
			return false;
		}

		$code = str_split($this->code);
		$sum = ($code[1] + $code[3] + $code[5] + $code[7] + $code[9] + $code[11]) * 3;
		$sum += $code[0] + $code[2] + $code[4] + $code[6] + $code[8] + $code[10];
		$sum = 10 - ($sum % 10);

		return (string) $sum === $code[12];
	}

	public function toSVG(string $width = '200px'): string
	{
		if (!$this->verify()) {
			throw new \LogicException('Invalid barcode: ' . $this->code);
		}

		static $guard = [1, 0, 1];
		static $center = [0, 1, 0, 1, 0];

		$bw = 3; //bar width
		$w = $bw * 106;
		$h = $bw * 50;
		$fs = 8 * $bw; //Font size
		$yt = 45 * $bw;
		$dx = 2 * $bw; //lengh between bar and text
		$x = 7 * $bw;
		$y = 2.5 * $bw;
		$sb = 35 * $bw;
		$lb = 45 * $bw;

		$char = $this->code;
		$first = substr($char, 0, 1);
		$first = (int) $first;
		$oe = self::PRITY[$first]; //Old event array for first number
		$char = str_split($char);

		$out = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>' . PHP_EOL;
		$out .= '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">' . PHP_EOL;
		$out .= sprintf('<svg viewBox="0 0 %d %d" width="%s" version="1.1" xmlns="http://www.w3.org/2000/svg">', $w, $h, $width) . PHP_EOL;

		$xt = $x + $dx - 8 * $bw; //Start point of text drawing
		$out .= sprintf('  <text x="%d" y="%d" font-family="monospace" font-size="%s">%s</text>', $xt, $yt, $fs, $char[0]) . PHP_EOL;

		// draw the left-most guarding bar
		foreach ($guard as $bar) {
			if ($bar === 1) {
				$out .= sprintf('  <rect x="%d" y="%d" width="%d" height="%d" fill="black" stroke-width="0" />', $x, $y, $bw, $lb) . PHP_EOL;
			}

			$x = $x + $bw;
		}

		// draw the left bars
		for ($i = 1; $i < 7; $i++) {
			$id = $i - 1; //id for Old-event array
			$oev = !$oe[$id]; //Old-event value
			$val = self::BARTABLE[$char[$i]][$oev];

			$xt = $x + $dx;

			$out .= sprintf(' <text x="%d" y="%d" font-family="monospace" font-size="%d">%s</text>', $xt, $yt, $fs, $char[$i]) . PHP_EOL;

			$val = str_split($val);

			for ($j = 0; $j < 4; $j++) {
				$num = (int) $val[$j];
				$w = $bw * $num;

				if ($j % 2) {
					$out .= sprintf('  <rect x="%d" y="%d" width="%d" height="%d" fill="black" stroke-width="0" />', $x, $y, $w, $sb) . PHP_EOL;
				}

				$x = $x + $w;
			}
		}

		// draw the center bar
		foreach ($center as $bar) {
			if ($bar === 1) {
				$out .= sprintf('  <rect x="%d" y="%d" width="%d" height="%d" fill="black" stroke-width="0" />', $x, $y, $bw, $lb) . PHP_EOL;
			}

			$x = $x + $bw;
		}

		// Draw the right bars, always in first column
		for ($i = 7; $i < 13; $i++) {
			$val = self::BARTABLE[$char[$i]][0];
			$xt = $x + $dx;

			$out .= sprintf(' <text x="%d" y="%d" font-family="monospace" font-size="%d">%s</text>', $xt, $yt, $fs, $char[$i]) . PHP_EOL;

			$val = str_split($val);

			for ($j = 0; $j < 4; $j++) {
				$num = (int) $val[$j];
				$w = $bw * $num;

				if (!($j % 2)) {
					$out .= sprintf('  <rect x="%d" y="%d" width="%d" height="%d" fill="black" stroke-width="0" />', $x, $y, $w, $sb) . PHP_EOL;
				}

				$x = $x + $w;
			}
		}

		// Draw the ending guard bar
		foreach ($guard as $bar) {
			if ($bar === 1) {
				$out .= sprintf('  <rect x="%d" y="%d" width="%d" height="%d" fill="black" stroke-width="0" />', $x, $y, $bw, $lb) . PHP_EOL;
			}

			$x = $x + $bw;
		}

		$out .= '</svg>' . PHP_EOL;

		return $out;
	}
}
