<?php
/*
	This file is part of KD2FW -- <http://dev.kd2.org/>

	Copyright (c) 2001-2021 BohwaZ <http://bohwaz.net/>
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

namespace KD2\Graphics\SVG;

class Bar
{
	protected $width = null;
	protected $height = null;
	protected $data = array();
	protected $title = null;
	protected $legend = true;
	protected $count, $min, $max, $margin_top, $margin_left;

	public function __construct($width = 600, $height = 400)
	{
		$this->width = (int) $width;
		$this->height = (int) $height;
	}

	public function add(Bar_Data_Set $data)
	{
		$this->data[] = $data;
		return true;
	}

	public function setTitle($title)
	{
		$this->title = $title;
		return true;
	}

	public function toggleLegend()
	{
		$this->legend = !$this->legend;
	}

	public function display()
	{
		header('Content-Type: image/svg+xml');
		echo $this->output();
	}

	protected function encodeText($str)
	{
		return htmlspecialchars($str, ENT_XML1, 'UTF-8');
	}

	public function output()
	{
		$out = '<?xml version="1.0" encoding="utf-8" standalone="no"?>' . PHP_EOL;
		$out.= '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.0//EN" "http://www.w3.org/TR/SVG/DTD/svg10.dtd">' . PHP_EOL;
		$out.= '<svg width="'.$this->width.'" height="'.$this->height.'" viewBox="0 0 '.$this->width.' '.$this->height.'" xmlns="http://www.w3.org/2000/svg" version="1.1">' . PHP_EOL;

		$out .= '<filter id="blur"><feGaussianBlur in="SourceGraphic" stdDeviation="2" /></filter>' . PHP_EOL;

		$out .= $this->_renderBarGraph();

		if ($this->title)
		{
			$out .= '<text x="'.($this->width * 0.98).'" y="'.($this->height * 0.07).'" font-size="'.($this->height * 0.05).'" fill="white" '
				.	'stroke="white" stroke-width="'.($this->height * 0.01).'" stroke-linejoin="round" stroke-linecap="round" '
				.	'text-anchor="end" style="font-family: Verdana, Arial, sans-serif; font-weight: bold;">'.$this->encodeText($this->title).'</text>' . PHP_EOL;
			$out .= '<text x="'.($this->width * 0.98).'" y="'.($this->height * 0.07).'" font-size="'.($this->height * 0.05).'" fill="black" '
				.	'text-anchor="end" style="font-family: Verdana, Arial, sans-serif; font-weight: bold;">'.$this->encodeText($this->title).'</text>' . PHP_EOL;
		}

		if ($this->legend && count($this->data))
		{
			$x = $this->width - ($this->width * 0.06);
			$y = $this->height * 0.1;

			$bars = [];

			foreach ($this->data as $row) {
				foreach ($row->bars as $bar) {
					$bars[$bar->label] = $bar->color;
				}
			}

			foreach ($bars as $label => $color)
			{
				$out .= '<rect x="'.$x.'" y="'.($y - $this->height * 0.01).'" width="'.($this->width * 0.04).'" height="'.($this->height * 0.04).'" fill="'.$color.'" stroke="black" stroke-width="1" rx="2" />' . PHP_EOL;
				$out .= $this->text($x-($this->width * 0.02), $y+($this->height * 0.025), $label, 'black', $this->height * 0.05, 'white', 'end');
				$out .= $this->text($x-($this->width * 0.02), $y+($this->height * 0.025), $label, 'black', $this->height * 0.05, null, 'end');

				$y += ($this->height * 0.07);
			}
		}



		$out .= '</svg>';
		return $out;
	}

	protected function _renderBarGraph()
	{
		$out = '';

		if (!count($this->data))
		{
			return $out;
		}

		$bars_count = 0;

		// Figure out the minimum/maximum Y-axis value
		foreach ($this->data as $row)
		{
			$values = $row->getValues();
			$count = count($values);
			$bars_count += $count;

			if ($count < 1) {
				continue;
			}

			if (!isset($this->count)) {
				$this->count = $count;
			}

			$this->max = max((int)$this->max, max($values));
			$this->min = min((int)$this->min, min($values));
		}

		$this->max = max($this->max, 1);

		if (empty($this->count)) {
			return $out;
		}

		$this->margin_left = $this->width * 0.1;
		$this->margin_top = $this->height * 0.1;

		$bar_width = floor(($this->width - $this->margin_left - (count($this->data) * 10)) / $bars_count) - 2;
		$bar_width = max(2, $bar_width);

		$range = $this->max - $this->min;
		$step = $this->stepValue($range, 7) ?: 1;

		$lines = [];
		$min = round($this->min / $step)*$step;

		for ($i = $min; $i <= $this->max; $i += $step) {
			$lines[] = $i;
		}

		$y = 10 + $this->height - $this->margin_top;
		$axis_height = $y / count($lines);

		// Horizontal lines and Y axis legends
		foreach ($lines as $v) {
			$out .= sprintf('<line x1="%f" y1="%f" x2="%f" y2="%f" stroke-width="1" stroke="rgba(127, 127, 127, 0.5)" />' . PHP_EOL, $this->margin_left, $y, $this->width, $y);

			$out .= sprintf('<g><text x="%f" y="%f" font-size="%f" fill="gray" text-anchor="end" style="font-family: Verdana, Arial, sans-serif;">%s</text></g>' . PHP_EOL, $this->width * 0.08, $y, $this->height * 0.04, round($v) ?: 0);
			$y -= $axis_height + 1;
		}

		$plot_height = $this->height - ($this->height * 0.17);
		$x = $this->margin_left + 4;

		foreach ($this->data as $group)
		{
			$group_width = $bar_width * count($group->bars) + 5;

			$y = $this->height - $this->margin_top + 10 + $this->height * 0.04;
			$out .= $this->text($x + $group_width / 2 - 5, $y, $group->label, '#666', $this->height * 0.03, null, 'middle');

			foreach ($group->bars as $bar) {
				$h = floor(($bar->value / $this->max) * $plot_height);
				$y = $this->height - $this->margin_top + 10 - $h;
				$out .= sprintf('<rect x="%d" y="%d" width="%d" height="%d" fill="%s" />',
					$x, $y, $bar_width, $h, $bar->color) . PHP_EOL;
				$x += $bar_width + 2;
			}

			$x += 10;
		}

		return $out;
	}

	protected function stepValue(float $range, float $targetSteps)
	{
		$tempStep = $range / $targetSteps;
		$mag = floor(log10($tempStep));
		$magPow = pow(10, $mag) ?: 1;
		$magMsd = (int)($tempStep/$magPow + 0.5);

		if ($magMsd > 5)
			$magMsd = 10;
		else if ($magMsd > 2)
			$magMsd = 5;
		else if ($magMsd > 1)
			$magMsd = 2;

		return $magMsd*$magPow;
	}

	protected function y($value)
	{
		return 10 + $this->height - $this->margin_top - (($value - $this->min)*($this->height - $this->margin_top))/(($this->max - $this->min)?:1);
	}

	protected function text($x, $y, $content, $color, ?float $size = null, ?string $stroke_color = null, $anchor = 'start')
	{
		$stroke = '';

		if ($stroke_color) {
			$stroke = sprintf('stroke-width="%f" stroke-linejoin="round" stroke-linecap="round" stroke="%s" filter="url(#blur)"', $this->height * 0.01, $stroke_color);
		}

		return sprintf('<text x="%f" y="%f" font-size="%f" fill="%s" text-anchor="%s" style="font-family: Verdana, Arial, sans-serif;" %s>%s</text>' . PHP_EOL,
			$x, $y, $size ?: $this->height * 0.05, $color, $anchor, $stroke, $this->encodeText($content));
	}
}

class Bar_Data_Set
{
	public $label = null;
	public $bars = [];

	public function __construct(?string $label = null)
	{
		$this->label = $label;
	}

	public function add(float $value, ?string $label = null, ?string $color = 'blue')
	{
		$this->bars[] = (object) compact('value', 'label', 'color');
	}

	public function getValues(): array
	{
		$out = [];

		foreach ($this->bars as $bar) {
			$out[] = $bar->value;
		}

		return $out;
	}
}
