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

class Pie
{
	protected $width = null;
	protected $height = null;
	protected $data = array();
	protected $title = null;
	protected $legend = true;
	protected $percentage = false;

	public function __construct($width = 600, $height = 400)
	{
		$this->width = (int) $width;
		$this->height = (int) $height;
	}

	public function togglePercentage(bool $p)
	{
		$this->percentage = $p;
	}

	public function add(Pie_Data $data)
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

		$circle_size = min($this->width, $this->height);
		$cx = $circle_size / 2;
		$cy = $this->height / 2;
		$circle_size *= 0.98;
		$radius = $circle_size / 2;

		if (count($this->data) == 1)
		{
			$row = current($this->data);
			$out .= "<circle cx=\"{$cx}\" cy=\"{$cy}\" r=\"{$radius}\" fill=\"{$row->fill}\" "
				.	"stroke=\"white\" stroke-width=\"".($circle_size * 0.005)."\" stroke-linecap=\"round\" "
				.	"stroke-linejoin=\"round\" />";
		}
		else
		{
			$sum = 0;
			$end_angle = 0;

			foreach ($this->data as $row)
			{
				$sum += $row->data;
			}

			$percents = '';
			$count = count($this->data);

			foreach ($this->data as $i => $row)
			{
				$row->angle = ceil(360 * $row->data / ($sum ?: 1));

	            $start_angle = $end_angle;
	            $end_angle = $start_angle + $row->angle;

				$x1 = $cx + $radius * cos(deg2rad($start_angle));
				$y1 = $cy + $radius * sin(deg2rad($start_angle));

				$x2 = $cx + $radius * cos(deg2rad($end_angle));
				$y2 = $cy + $radius * sin(deg2rad($end_angle));

				$arc = $row->angle > 180 ? 1 : 0;

				$out .= "<path d=\"M{$cx},{$cy} L{$x1},{$y1} A{$radius},{$radius} 0 {$arc},1 {$x2},{$y2} Z\"
					fill=\"{$row->fill}\" stroke=\"white\" stroke-width=\"".($circle_size * 0.005)."\" stroke-linecap=\"round\"
					stroke-linejoin=\"round\" />";

				if ($this->percentage) {
					// https://stackoverflow.com/questions/48710188/calculate-the-center-point-of-a-arc-wedge
					$percent = round(($row->data / $sum) * 100);
					$a1 = deg2rad($start_angle);
					$a2 = deg2rad($end_angle);
					$a = ($a1 + ($a2 > $a1 ? $a2 : $a2 + pi()*2)) * 0.5;
					$r = ($radius * (0.5 + ($i / $count) * 0.5)); // Spiral of percentages to avoid overlap
					$x = $cx + cos($a) * $r;
					$y = $cy + sin($a) * $r;
					$percents .= $this->text($x, $y, $percent . '%', '#fff', $this->height * 0.05, 'white', 'middle');
					$percents .= $this->text($x, $y, $percent . '%', 'black', $this->height * 0.05, null, 'middle');
				}
			}

			$out .= $percents;
		}

		if ($this->title)
		{
			$out .= '<text x="'.($this->width * 0.98).'" y="'.($this->height * 0.07).'" font-size="'.($this->height * 0.05).'" fill="white" '
				.	'stroke="white" stroke-width="'.($this->height * 0.01).'" stroke-linejoin="round" stroke-linecap="round" '
				.	'text-anchor="end" style="font-family: Verdana, Arial, sans-serif; font-weight: bold;">'.$this->encodeText($this->title).'</text>' . PHP_EOL;
			$out .= '<text x="'.($this->width * 0.98).'" y="'.($this->height * 0.07).'" font-size="'.($this->height * 0.05).'" fill="black" '
				.	'text-anchor="end" style="font-family: Verdana, Arial, sans-serif; font-weight: bold;">'.$this->encodeText($this->title).'</text>' . PHP_EOL;
		}

		if ($this->legend)
		{
			$x = $this->width - ($this->width * 0.06);
			$y = $this->height * 0.1;

			foreach ($this->data as $row)
			{
				$h = $row->sublabel ? 0.1 : 0.05;
				$out .= '<rect x="'.($x - $this->width * 0.01).'" y="'.($y - $this->height * 0.015).'" width="'.($this->width * 0.04).'" height="'.($this->height * $h).'" fill="'.$row->fill.'" stroke="black" stroke-width="1" rx="2" />' . PHP_EOL;

				if ($row->label) {
					$out .= $this->text($x-($this->width * 0.02), $y+($this->height * 0.025), $row->label, 'white', null, 'rgba(255, 255, 255, 0.5)', 'end');
					$out .= $this->text($x-($this->width * 0.02), $y+($this->height * 0.025), $row->label, 'black', null, null, 'end');
				}

				if ($row->sublabel) {
					$y += ($this->height * 0.06);
					$out .= $this->text($x-($this->width * 0.02), $y+($this->height * 0.02), $row->sublabel, '#666', 12, null, 'end');
				}

				$y += ($this->height * 0.08);
			}
		}

		$out .= '</svg>';
		return $out;
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

class Pie_Data
{
	public $fill = 'blue';
	public $data = 0.0;
	public $label = null;
	public $sublabel = null;
	public $angle;

	public function __construct($data, $label = null, $fill = 'blue')
	{
		$this->data = $data;
		$this->fill = $fill;
		$this->label = $label;
	}
}
