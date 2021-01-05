<?php

require __DIR__ . '/vendor/autoload.php';

use App\Pdf\Data\Color;
use App\Pdf\Generator;
use Illuminate\Support\Carbon;
use Wujunze\Colors;

$clients = json_decode(file_get_contents(output_path('clients.json')));

$client = $clients[0];

// $invoice_number = $client->{'current-invoice-number'};

$invoice_number = random_int(1, 1000);

$filename = sprintf("%s-%'.06d.pdf", $client->id, $invoice_number);

$filepath = invoice_path($filename);

$blue = new Color(47, 98, 253);
$gray = new Color(145, 156, 158);

$gen = new Generator();

$addItem = function (array &$list, int &$height, string $title, string $subtitle, int $price, int $quantity) use ($gray) {
    array_push($list, [
        'type' => 'text',
        'position' => ['x' => 58, 'y' => $height],
        'text' => $title,
        'font' => [
            'max_width' => 450 - 58 - 20,
        ],
    ],
    [
        'type' => 'text',
        'position' => ['x' => 58, 'y' => $height + 15],
        'text' => $subtitle,
        'font' => [
            'size' => 9,
            'color' => $gray,
            'max_width' => 450 - 58 - 20,
        ],
    ],
    [
        'type' => 'text',
        'position' => ['x' => 450, 'y' => $height],
        'text' => sprintf('$%d', $price),
        'font' => [
            'max_width' => 65,
        ],
    ],
    [
        'type' => 'text',
        'position' => ['x' => 612 - 58, 'y' => $height],
        'text' => $quantity,
        'font' => [
            'align' => 'right',
            'max_width' => 30,
        ],
    ]);

    $height += 35;
};

function prompt(string $name, object $prompt, string $type = null) {
    $type = strtolower($type ?? $prompt->type ?? 'string');
    $result = null;

    if ($type === 'string') {
        print(Colors::initColoredString($prompt->name, 'cyan'));
        print(' >> ');

        $result = trim(fgets(STDIN));
    } else if ($type === 'int') {
        [, $output] = prompt($name, $prompt, 'string');

        if (ctype_digit($output)) {
            $result = intval($output);
        } else {
            print(Colors::error('Please enter a valid whole number.'));

            return prompt($name, $prompt);
        }
    } else if ($type === 'date') {
        [, $output] = prompt($name, $prompt, 'string');

        $input_format = $prompt->{'date-input-format'} ?? 'm/Y';
        $output_format = $prompt->{'date-output-format'} ?? 'm/Y';

        $d = DateTime::createFromFormat($input_format, $output);

        if ($d && $d->format($input_format) === $output) {
            $result = $d->format($output_format);
        } else {
            print(Colors::error(sprintf('Please enter a date in the format "%s".', $input_format)));

            return prompt($name, $prompt);
        }
    }

    if (isset($prompt->default) && $result === '') {
        Colors::success(sprintf('Using default value "%s"', $prompt->default));
        $result = $prompt->default;
    }

    return [
        sprintf('{{ %s }}', $name),
        $result,
    ];
}

$items = $client->items;

$count = 0;
$total = 0;
$list = [];
$height = 270;

foreach ($items as $item) {
    $prompts = [];

    if (isset($item->prompts)) {
        $prompts = get_object_vars($item->prompts);
    }

    $searches = [];
    $replacements = [];

    foreach ($prompts as $name => $prompt) {
        [$search, $replace] = prompt($name, $prompt);

        $searches[] = $search;
        $replacements[] = $replace;
    }

    $title = str_replace($searches, $replacements, (string) $item->title);
    $subtitle = str_replace($searches, $replacements, (string) $item->subtitle);
    $price = str_replace($searches, $replacements, (string) $item->price);
    $quantity = str_replace($searches, $replacements, (string) $item->quantity);

    $price = intval($price);
    $quantity = intval($quantity);
    $total += $price * $quantity;

    $addItem($list, $height, $title, $subtitle, $price, $quantity);

    $count++;
}

$pdf = $gen->render([
    // Background & Header
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
        'image' => resource_path('images/invoice.png'),
    ],


    // Billed To
    [
        'type' => 'text',
        'position' => ['x' => 58, 'y' => 145],
        'text' => 'Billed To',
        'font' => [
            'size' => 9,
            'color' => $blue,
        ],
    ],
    [
        'type' => 'text',
        'position' => ['x' => 58, 'y' => 160],
        'text' => $client->{'billed-to'},
    ],

    // Invoice Number
    [
        'type' => 'text',
        'position' => ['x' => 280, 'y' => 145],
        'text' => 'Invoice Number',
        'font' => [
            'size' => 9,
            'color' => $blue,
        ],
    ],
    [
        'type' => 'text',
        'position' => ['x' => 280, 'y' => 160],
        'text' => sprintf("%'.06d", $invoice_number),
    ],

    // Date of Issue
    [
        'type' => 'text',
        'position' => ['x' => 390, 'y' => 145],
        'text' => 'Date of Issue',
        'font' => [
            'size' => 9,
            'color' => $blue,
        ],
    ],
    [
        'type' => 'text',
        'position' => ['x' => 390, 'y' => 160],
        'text' => Carbon::today()->format('m/d/Y'),
    ],

    // Invoice Total
    [
        'type' => 'text',
        'position' => ['x' => 612 - 58, 'y' => 145],
        'text' => 'Invoice Total',
        'font' => [
            'size' => 9,
            'align' => 'right',
            'color' => $blue,
        ],
    ],
    [
        'type' => 'text',
        'position' => ['x' => 612 - 58, 'y' => 160],
        'text' => sprintf('$%d', $total),
        'font' => [
            'size' => 26,
            'align' => 'right',
        ],
    ],


    // Item
    [
        'type' => 'text',
        'position' => ['x' => 58, 'y' => 250],
        'text' => 'Item',
        'font' => [
            'size' => 9,
            'color' => $blue,
        ],
    ],

    // Price
    [
        'type' => 'text',
        'position' => ['x' => 450, 'y' => 250],
        'text' => 'Price',
        'font' => [
            'size' => 9,
            'color' => $blue,
        ],
    ],

    // Qty
    [
        'type' => 'text',
        'position' => ['x' => 612 - 58, 'y' => 250],
        'text' => 'Qty',
        'font' => [
            'size' => 9,
            'align' => 'right',
            'color' => $blue,
        ],
    ],


    ...$list,


    // Thanks & Footer
    [
        'type' => 'text',
        'position' => ['x' => 58, 'y' => 792 - 58 - 21 - 60],
        'text' => 'It is truly a pleasure to serve you!',
        'font' => [
            'size' => 9,
            'color' => $blue,
        ],
    ],
    [
        'type' => 'textarea',
        'position' => ['x' => 58, 'y' => 792 - 58 - 21],
        'text' => "Pilot Communications Group\nPO BOX 332, Thompson's Station, TN 37179",
        'font' => [
            'size' => 9,
            'line_spacing' => 3,
            'color' => $blue,
        ],
    ],
]);

file_put_contents(output_path('clients.json'), json_encode($clients, JSON_PRETTY_PRINT));

file_put_contents($filepath, $pdf->contents());

@exec(sprintf('explorer.exe /select, "%s"', $filepath));
// @exec(sprintf('start "" "%s"', $filepath));
