<?php

require __DIR__ . '/vendor/autoload.php';

use App\Pdf\Data\Color;
use App\Renderers\Renderer;

$renderer = new Renderer();

$pdf = $renderer->render([
    [
        'type' => 'rect',
        'position' => ['x' => 0, 'y' => 0],
        'size' => ['width' => 612, 'height' => 792],
        'fill' => new Color(243, 245, 248),
    ],

    [
        'type' => 'image',
        'position' => ['x' => 58, 'y' => 58],
        'size' => ['width' => 175],
        'image' => 'resources/images/invoice.png',
    ],

    [
        'type' => 'text',
        'position' => ['x' => 58, 'y' => 250],
        'text' => 'Item',
        'font' => [
            'family' => 'FiraSans',
            'style' => 'Normal',
            'size' => 12,
            'color' => new Color(47, 98, 253),
        ],
    ],

    [
        'type' => 'text',
        'position' => ['x' => 58, 'y' => 285],
        'text' => 'Maintenance & Services',
        'font' => [
            'family' => 'FiraSans',
            'style' => 'Normal',
            'size' => 12,
            'color' => new Color(145, 156, 158),
            'max_width' => 200,
        ],
    ],
]);

file_put_contents('output/test.pdf', $pdf->contents());
