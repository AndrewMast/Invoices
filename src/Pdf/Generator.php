<?php

namespace Invoices\Pdf;

use Invoices\Pdf\Data\Color;
use Invoices\Pdf\Creator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Generator {
	protected $settings = [];

	public function __construct($settings = []) {
		$this->settings = array_merge_recursive_distinct([
            'orientation' => 'p',
            'unit' => 'pt',
            'size' => 'letter',
            'fonts' => new Collection([
                ['Winstead', 'Normal', 'Winstead.php'],
                ['FiraSans', 'Normal', 'FiraSans.php'],
            ]),
            'defaults' => [
                'position' => ['x' => 0, 'y' => 0],
                'size' => ['width' => 0, 'height' => 0],
                'text' => '',
                'font' => [
                    'family' => 'FiraSans',
                    'style' => 'Normal',
                    'size' => 12,
                    'line_spacing' => 3,
                    'color' => new Color(30, 30, 30),
                    'align' => 'left',
                    'transform' => 'none',
                    'max_width' => -1,
                ],
                'fill' => new Color(120, 120, 120),
            ],
        ], $settings ?? []);
	}

	public function render($items, Creator $pdf = null) {
		$pdf = $pdf ?? new Creator(null, $this->settings['orientation'], $this->settings['unit'], $this->settings['size']);
		$pdf->addPage();
		$pdf->setAutoPageBreak(false);

		foreach ($this->settings['fonts'] ?? [] as $font) {
			$pdf->addFont(...$font);
		}

		foreach ($items as $item) {
			$this->renderItem($this->applyDefaults($item), $pdf);
		}

		return $pdf;
	}

	public function renderItem($item, Creator $pdf) {
		if (!isset($item['type'])) {
			return;
		}

		if (method_exists($this, 'render' . Str::studly($item['type']) . 'Item')) {
			return $this->{'render' . Str::studly($item['type']) . 'Item'}($item, $pdf);
		} elseif (strtolower($item['type']) == 'command') {
			$commands = Arr::wrap(object_to_array($item['command'] ?? []));

			foreach ($commands as $command => $arguments) {
				if (is_numeric($command)) {
					list($command, $arguments) = [$arguments, []];
				}

				$arguments = Arr::wrap($arguments);

				$pdf->{$command}(...$arguments);
			}
		}
	}

	public function renderTextItem($item, Creator $pdf) {
		$size = $item['font']['size'];

        $text = $this->transformText($item['text'], $item['font']['transform']);

		$pdf->setFont(
            $item['font']['family'],
            $item['font']['style'],
            $item['font']['size']
        );

		$pdf->setTextColor(...$item['font']['color']->array());

		$maxWidth = $item['font']['max_width'];
		$textWidth = $pdf->getStringWidth($text);

		if ($maxWidth > 0 && $textWidth > $maxWidth) {
			$pdf->setFontSize(($maxWidth / $textWidth) * $size);
		}

		$pdf->setXY($item['position']['x'], $item['position']['y']);
		$pdf->cell(1, $size, $text, 0, 2, $item['font']['align']);
	}

	public function renderTextareaItem($item, Creator $pdf) {
		$text = $this->transformText($item['text'], $item['font']['transform']);

        $pdf->setFont(
            $item['font']['family'],
            $item['font']['style'],
            $item['font']['size']
        );

        $pdf->setTextColor(...$item['font']['color']->array());

        $pdf->setXY($item['position']['x'], $item['position']['y']);
		$pdf->multiCell($item['size']['width'], $item['font']['size'] + $item['font']['line_spacing'], $text);
	}

	public function renderLineItem($item, Creator $pdf) {
		$pdf->line(
            $item['position']['x'],
            $item['position']['y'],
            $item['position']['x'] + $item['size']['width'],
            $item['position']['y'] + $item['size']['height']
        );
	}

    public function renderRectItem($item, Creator $pdf) {
        $pdf->setFillColor(...$item['fill']->array());

        $pdf->rect(
            $item['position']['x'],
            $item['position']['y'],
            $item['size']['width'],
            $item['size']['height'],
            'F'
        );
    }

	public function renderImageItem($item, Creator $pdf) {
		$image = $item['image'];

		if ($image == null) {
			return;
		}

		if (preg_match('/^data:image\/([A-Za-z0-9]+);base64/', $image, $matches)) {
			$type = $matches[1];
		} else if (filter_var($image, FILTER_VALIDATE_URL)) {
			$type = pathinfo($image, PATHINFO_EXTENSION);

			$image = 'data:image/' . $type . ';base64,' . base64_encode(file_get_contents($image));
		} else {
			$type = pathinfo($image, PATHINFO_EXTENSION);
		}

		$pdf->image(
            $image,
            $item['position']['x'],
            $item['position']['y'],
            $item['size']['width'],
            $item['size']['height'],
            $type
        );
	}

	protected function transformText($text, $transform) {
		switch (strtolower($transform)) {
			case 'lowercase':
				$text = strtolower($text);
				break;
			case 'uppercase':
				$text = strtoupper($text);
				break;
			case 'capitalize':
				$text = Str::title($text);
				break;
			default:
				break;
		}

		return $text;
	}

    protected function applyDefaults($item) {
        $settings = array_merge_recursive_distinct($this->settings['defaults'], $item);

        foreach (['font', 'position', 'size'] as $key) {
            $settings[$key] = optional($settings[$key]);
        }

        switch (strtolower($settings['font']['align'] ?? '')) {
            case 'left':
                $settings['font']['align'] = 'L';
                break;
            case 'right':
                $settings['font']['align'] = 'R';
                break;
            case 'center':
                $settings['font']['align'] = 'C';
                break;
            default:
                $settings['font']['align'] = 'L';
        }

        return optional($settings);
    }
}
