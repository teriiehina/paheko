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

namespace KD2\Graphics\SVG;

class Plot
{
	const POSITION_TOP_RIGHT = 1;
	const POSITION_BOTTOM_RIGHT = 2;

	protected $width = null;
	protected $height = null;
	protected $data = array();
	protected $title = null;
	protected $labels = array();
	protected $legend = true;
	protected $legend_position = self::POSITION_TOP_RIGHT;
	protected $count, $min, $max, $margin_top, $margin_left;

	public function __construct($width = 600, $height = 400)
	{
		$this->width = (int) $width;
		$this->height = (int) $height;
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

	public function setLegendPosition(int $position)
	{
		$this->legend_position = $position;
	}

	public function setLabels($labels)
	{
		$this->labels = $labels;
		return true;
	}

	public function add(Plot_Data $data)
	{
		$this->data[] = $data;
		return true;
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

		if ($this->title)
		{
			$out .= '<text x="'.round($this->width/2).'" y="'.($this->height * 0.07).'" font-size="'.($this->height * 0.05).'" fill="white" '
				.	'stroke="white" stroke-width="'.($this->height * 0.01).'" stroke-linejoin="round" stroke-linecap="round" '
				.	'text-anchor="middle" style="font-family: Verdana, Arial, sans-serif; font-weight: bold;">'.$this->encodeText($this->title).'</text>' . PHP_EOL;
			$out .= '<text x="'.round($this->width/2).'" y="'.($this->height * 0.07).'" font-size="'.($this->height * 0.05).'" fill="black" '
				.	'text-anchor="middle" style="font-family: Verdana, Arial, sans-serif; font-weight: bold;">'.$this->encodeText($this->title).'</text>' . PHP_EOL;
		}

		$out .= $this->_renderLinegraph();

		if ($this->legend)
		{
			if ($this->legend_position == self::POSITION_BOTTOM_RIGHT) {
				$x = $this->width - ($this->width * 0.06);
				$y = $this->height * 0.9 - ($this->height * 0.07) * count($this->data);
			}
			else {
				$x = $this->width - ($this->width * 0.06);
				$y = $this->height * 0.1;
			}

			foreach ($this->data as $row)
			{
				$out .= '<rect x="'.$x.'" y="'.($y - $this->height * 0.01).'" width="'.($this->width * 0.04).'" height="'.($this->height * 0.04).'" fill="'.$row->color.'" stroke="black" stroke-width="1" rx="2" />' . PHP_EOL;

				if ($row->title)
				{
					$out .= '<text x="'.($x-($this->width * 0.02)).'" y="'.($y+($this->height * 0.025)).'" '
						.	'font-size="'.($this->height * 0.05).'" fill="white" stroke="white" '
						.	'stroke-width="'.($this->height * 0.01).'" stroke-linejoin="round" '
						.	'stroke-linecap="round" text-anchor="end" style="font-family: Verdana, Arial, '
						.	'sans-serif;">'.$this->encodeText($row->title).'</text>' . PHP_EOL;
					$out .= '<text x="'.($x-($this->width * 0.02)).'" y="'.($y+($this->height * 0.025)).'" '
						.	'font-size="'.($this->height * 0.05).'" fill="black" text-anchor="end" '
						.	'style="font-family: Verdana, Arial, sans-serif;">'.$this->encodeText($row->title).'</text>' . PHP_EOL;
				}

				$y += ($this->height * 0.07);
			}
		}

		$out .= '</svg>';

		return $out;
	}

	protected function _renderLinegraph()
	{
		$out = '';

		if (!count($this->data))
		{
			return $out;
		}

		// Figure out the minimum/maximum Y-axis value
		foreach ($this->data as $row)
		{
			if (count($row->get()) < 1) {
				continue;
			}

			if (null === $this->count) {
				$this->count = count($row->get());
			}

			$this->max = max((int)$this->max, max($row->get()));
			$this->min = min((int)$this->min, min($row->get()));
		}

		if ($this->count < 1) {
			return $out;
		}

		$this->margin_left = $this->width * 0.1;
		$this->margin_top = $this->height * 0.1;


		$range = $this->max - $this->min;
		$step = $this->stepValue($range, 7) ?: 1;

		$lines = [];
		$min = round($this->min / $step)*$step;

		for ($i = $min; $i <= $this->max; $i += $step) {
			$lines[] = $i;
		}

		// Horizontal lines and Y axis legends
		foreach ($lines as $v) {
			$y = $this->y($v);
			$out .= sprintf('<line x1="%f" y1="%f" x2="%f" y2="%f" stroke-width="1" stroke="rgba(127, 127, 127, 0.5)" />' . PHP_EOL, $this->margin_left, $y, $this->width, $y);

			$out .= sprintf('<g><text x="%f" y="%f" font-size="%f" fill="gray" text-anchor="end" style="font-family: Verdana, Arial, sans-serif;">%s</text></g>' . PHP_EOL, $this->width * 0.08, $y, $this->height * 0.04, round($v));
		}

		// X-axis lines
		$y = 10 + $this->height - ($this->margin_top) + 2;
		$x = $this->margin_left;

		$axis_width = $this->width - $x;
		$column_width = 70 + $this->data[0]->width;
		$nb_items = ceil($axis_width / $column_width);
		$item_width = $axis_width / $this->count;
		$step = (int) max(1, $this->count / $nb_items);

		$i = 0;

		foreach ($this->data[0]->get() as $v)
		{
			if ($x >= $this->width) {
				break;
			}

			$out .= sprintf('<line x1="%d" y1="%d" x2="%d" y2="%d" stroke-width="1" stroke="%s" />', $x, $y, $x, 2, !($i % $step) ? 'rgba(127, 127, 127, 0.5)' : 'rgba(127, 127, 127, 0.2)');

			if (!($i % $step) && isset($this->labels[$i+1]))
			{
				$label = $this->encodeText($this->labels[$i+1]);
				$anchor = $x >= ($this->width - ($column_width / 3)) ? 'end': 'middle';
				$out .= sprintf('<g><text x="%f" y="%f" font-size="%s" fill="gray" text-anchor="%s" style="font-family: Verdana, Arial, sans-serif;">%s</text></g>' . PHP_EOL, $x, $y+($this->height * 0.05), $this->height * 0.04, $anchor, $label);
			}

			$i++;

			$x += $item_width;
		}

		foreach ($this->data as $row)
		{
			$out .= '<polyline fill="none" stroke="'.$row->color.'" stroke-width="'.$row->width.'" '
				.'stroke-linecap="round" points="';

			$i = 0;

			foreach ($row->get() as $v)
			{
				$x = $this->margin_left + ($item_width * $i++);
				$out.= sprintf('%f,%f ', $x, $this->y($v));
			}

			$out .= '" />' . PHP_EOL;
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
		return $this->height
			+ 2 // line thickness
			- $this->margin_top
			- (($value - $this->min)*($this->height - $this->margin_top))/(($this->max - $this->min)?:1);
	}
}

class Plot_Data
{
	public $color = 'blue';
	public $width = '3';
	public $title = null;
	protected $data = array();

	public function __construct($data, ?string $title = null, ?string $color = 'blue')
	{
		if (is_array($data)) {
			$this->data = $data;
		}
		elseif (!is_object($data)) {
			$this->append($data);
		}

		$this->title = $title;
		$this->color = $color;
	}

	public function append($data)
	{
		$this->data[] = $data;
		return true;
	}

	public function get()
	{
		return $this->data;
	}
}
