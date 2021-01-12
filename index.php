<?php

require __DIR__ . '/vendor/autoload.php';

use App\Pdf\Data\Color;
use App\Pdf\Generator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Wujunze\Colors;

$gen = new InvoiceGenerator;

print(
    Colors::initColoredString('black' . PHP_EOL, 'black') .
    Colors::initColoredString('dark_gray' . PHP_EOL, 'dark_gray') .
    Colors::initColoredString('blue' . PHP_EOL, 'blue') .
    Colors::initColoredString('light_blue' . PHP_EOL, 'light_blue') .
    Colors::initColoredString('green' . PHP_EOL, 'green') .
    Colors::initColoredString('light_green' . PHP_EOL, 'light_green') .
    Colors::initColoredString('cyan' . PHP_EOL, 'cyan') .
    Colors::initColoredString('light_cyan' . PHP_EOL, 'light_cyan') .
    Colors::initColoredString('red' . PHP_EOL, 'red') .
    Colors::initColoredString('light_red' . PHP_EOL, 'light_red') .
    Colors::initColoredString('purple' . PHP_EOL, 'purple') .
    Colors::initColoredString('light_purple' . PHP_EOL, 'light_purple') .
    Colors::initColoredString('brown' . PHP_EOL, 'brown') .
    Colors::initColoredString('yellow' . PHP_EOL, 'yellow') .
    Colors::initColoredString('light_gray' . PHP_EOL, 'light_gray') .
    Colors::initColoredString('white' . PHP_EOL, 'white')
);
die;

$clients = json_decode(file_get_contents(output_path('clients.json')));

$client_count = count($clients);

print(Colors::initColoredString('Clients:', 'cyan') . PHP_EOL);

foreach ($clients as $index => $client) {
    print(Colors::initColoredString(sprintf("  %'. 2d - %s", $index + 1, $client->name), 'cyan') . PHP_EOL);
}

print(PHP_EOL);

$client = $clients[selectClient($client_count)];

print(PHP_EOL);

function selectClient($count) {
    print(Colors::initColoredString('  Select Client', 'cyan') . ' >> ');

    $output = trim(fgets(STDIN));

    if (!ctype_digit($output)) {
        Colors::error('  Please enter a valid whole number.');

        return selectClient($count);
    }

    $client = intval($output);

    if ($client < 1 || $client > $count) {
        Colors::error('  Please select a valid client.');

        return selectClient($count);
    }

    return $client - 1;
}

$invoice_number = intval($client->{'next-invoice-number'});

print(sprintf(
    "%s%s: %s%s" .
    "%s%s: %s%s" .
    "%s%s: ",
    str_repeat(' ', 17 - 4),
    Colors::initColoredString('Name', 'green'),
    $client->name,
    PHP_EOL,
    str_repeat(' ', 17 - 9),
    Colors::initColoredString('Billed To', 'green'),
    $client->name,
    PHP_EOL,
    str_repeat(' ', 17 - (12 + strlen($invoice_number))),
    sprintf(
        '%s (%s)',
        Colors::initColoredString('Invoice #', 'green'),
        Colors::initColoredString($invoice_number, 'brown')
    )
));

$number = trim(fgets(STDIN));

if ($number === '') {
    print("\033[s\033[1A\033[19C$invoice_number\033[u");
    $number = $invoice_number;
}

if (intval($number) === $invoice_number) {
    $client->{'next-invoice-number'}++;
}

// $invoice_number = random_int(1, 1000);

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

function prompt(string $name, $prompt, string $type = null) {
    $type = strtolower($type ?? $prompt->type ?? 'string');
    $result = null;

    if ($type === 'string') {
        print(Colors::initColoredString($prompt->name, 'cyan') . ' >> ');

        $result = trim(fgets(STDIN));
    } else if ($type === 'int') {
        [, $output] = prompt($name, $prompt, 'string');

        if (ctype_digit($output)) {
            $result = intval($output);
        } else {
            Colors::error('Please enter a valid whole number.');

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
            Colors::error(sprintf('Please enter a date in the format "%s".', $input_format));

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

// @exec(sprintf('explorer.exe /select, "%s"', $filepath));
@exec(sprintf('start "" "%s"', $filepath));

class InvoiceGenerator {
    public function __construct() {
        $this->inputting = false;
        $this->input_ongoing = false;
        $this->input_validator = 'string';
        $this->input_nullable = false;
        $this->input_default = null;
        $this->input_prefix = '';

        $this->colors = (object) [
            'blue' => new Color(47, 98, 253),
            'gray' => new Color(145, 156, 158),
        ];

        $this->clients_file = output_path('clients.json');

        $this->loadSettings();

        $client_data = $this->chooseClient();

        $client = $client_data['client'];

        /*if ($client_data['newly-created'] === true) {
            do something
        }*/

        $invoice_number = $client_data['invoice-number'];

        $this->createPdf($client, $invoice_number);
    }

    public function createPdf($client, int $invoice_number) {
        $filename = sprintf("%s-%'.06d.pdf", $client->id, $invoice_number);
        $filepath = invoice_path($filename);

        $generator = new Generator;

        $pdf = $generator->render($this->getRenderItems($client, $invoice_number));

        file_put_contents($filepath, $pdf->contents());

        $this->success("Successfully generated the invoice for {$client->name}!")->nl();

        $open = $this->v('bool', true, false)->input([
            'Do you want to open the invoice? [',
            ['y', 'brown'],
            '/',
            ['N', 'brown'],
            ']'
        ]);

        $this->printBack(1, 42, $open ? 'Yes' : 'No');

        if ($open) {
            @exec(sprintf('start "" "%s"', $filepath));
        } else {
            @exec(sprintf('explorer.exe /select, "%s"', $filepath));
        }
    }

    public function loadSettings() {
        $this->clients = json_decode(file_get_contents($this->clients_file));

        return $this;
    }

    public function saveSettings() {
        file_put_contents($this->clients_file, json_encode($this->clients, JSON_PRETTY_PRINT));

        return $this;
    }

    public function chooseClient() {
        $this->print('Clients:', 'cyan', true)->print([
            ['   0', 'light_green'],
            ' - ',
            ['Custom', 'cyan'],
        ], null, true);

        foreach ($this->clients as $i => $client) {
            $this->print([
                [sprintf("%'. 4d", $i + 1), 'light_green'],
                ' - ',
                [$client->name, 'cyan'],
            ], null, true);
        }

        $this->nl();

        $count = count($this->clients);

        $index = $this->v(['int', function ($input, &$message) use ($count) {
            $message = 'Please select a valid client.';

            return $input >= 0 && $input <= $count;
        }])->input('Select client');

        if ($index === 0) {
            $this->nl();

            $id = $this->v(function($input, &$message) {
                $message = 'Please enter a string using only lowercase letters.';

                return ctype_lower($input);
            })->space(15)->input('Id');

            $name = $this->v()->space(13)->input('Name');

            $billed = $this->v()->space(8)->input('Billed To');

            $invoice = $this->v('int', true)->space(4)->input(['Invoice # (', ['1', 'brown'], ')']);

            if ($invoice === null) {
                $invoice = 1;

                $this->printBack(1, 21, $invoice);
            }

            $this->nl();

            return [
                'client' => (object) [
                    'id' => $id,
                    'name' => $name,
                    'billed-to' => $billed,
                    'items' => [],
                ],
                'invoice-number' => $invoice,
            ];
        } else {
            $client = $this->clients[$index - 1];

            $this->nl();

            $next_invoice = $client->{'next-invoice-number'};

            $count = strlen($next_invoice);

            $this->space(14 + $count)->print([
                ['Id', 'cyan'],
                [' >> ', 'dark_gray'],
                $client->id,
            ], null, true);

            $this->space(12 + $count)->print([
                ['Name', 'cyan'],
                [' >> ', 'dark_gray'],
                $client->name,
            ], null, true);

            $this->space(7 + $count)->print([
                ['Billed To', 'cyan'],
                [' >> ', 'dark_gray'],
                $client->{'billed-to'},
            ], null, true);

            $invoice = $this->v('int', true)->space(4)->input([
                'Invoice # (',
                [$next_invoice, 'brown'],
                ')',
            ]);

            if ($invoice === null) {
                $invoice = $next_invoice;

                $this->printBack(1, 20 + $count, $invoice);
            }

            if ($invoice === $next_invoice) {
                $client->{'next-invoice-number'}++;
            }

            $this->nl();

            return [
                'client' => $client,
                'invoice-number' => $invoice,
            ];
        }
    }

    public function v($validator = 'string', $nullable = false, $default = null) {
        $this->inputting = false;
        $this->input_ongoing = true;
        $this->input_validator = $validator;
        $this->input_nullable = $nullable;
        $this->input_default = $default;
        $this->input_prefix = '';

        return $this;
    }

    public function input($message, string $color = 'cyan') {
        $this->inputting = true;

        if ($this->input_ongoing) {
            $this->print($this->input_prefix);
        }

        $this->print([[$message, $color], [' >> ', 'dark_gray']]);

        $stdin = fgets(STDIN);

        if ($stdin === false) {
            die; // Ctrl+C
        }

        $output = trim($stdin);

        $validators = Arr::wrap($this->input_ongoing ? $this->input_validator : 'string');

        foreach ($validators as $validator) {
            if (is_callable($validator)) {
                $error = '';

                if (!call_user_func_array($validator, [&$output, &$error])) {
                    $this->error($error);

                    return $this->input($message, $color);
                }
            } else if (is_string($validator)) {
                if ($output === '' && $this->input_nullable) {
                    $output = $this->input_default;
                } else if ($validator === 'string') {
                    if ($output === '') {
                        $this->error('Please enter a value.');

                        return $this->input($message, $color);
                    }
                } else if ($validator === 'int') {
                    if (!ctype_digit($output)) {
                        $this->error('Please enter a valid whole number.');

                        return $this->input($message, $color);
                    } else {
                        $output = intval($output);
                    }
                } else if ($validator === 'bool') {
                    if (in_array(strtolower($output), ['1', 'y', 'yes', 't', 'true'])) {
                        $output = true;
                    } else if (in_array(strtolower($output), ['0', 'n', 'no', 'f', 'false'])) {
                        $output = false;
                    } else {
                        $this->error('Please enter a valid yes or no answer.');

                        return $this->input($message, $color);
                    }
                }
            }
        }

        $this->clearInput();

        return $output;
    }

    public function clearInput() {
        $this->inputting = false;
        $this->input_ongoing = false;
        $this->input_validator = 'string';
        $this->input_nullable = false;
        $this->input_default = null;
        $this->input_prefix = '';

        return $this;
    }

    public function print($message = null, string $color = null, bool $newline = false) {
        if (is_array($message)) {
            foreach ($message as $part) {
                if (is_array($part)) {
                    $this->print(...$part);
                } else {
                    $this->print($part, $color);
                }
            }

            return $newline ? $this->nl() : $this;
        } else if ($message === null) {
            $message = PHP_EOL;
        } else {
            $message = Colors::initColoredString($message, $color) . ($newline ? PHP_EOL : '');
        }

        if ($this->input_ongoing && !$this->inputting) {
            $this->input_prefix .= $message;
        } else {
            fwrite(STDOUT, $message);
        }

        return $this;
    }

    public function printBack($rows, $cols, $message, string $color = null) {
        return $this->print(sprintf("\033[s\033[%dA\033[%dC%s\033[u", $rows, $cols, $message), $color);
    }

    public function nl(int $count = 1) {
        return $this->print(str_repeat(PHP_EOL, $count));
    }

    public function space(int $count = 1) {
        return $this->print(str_repeat(' ', $count));
    }

    public function error(string $message, bool $newline = true) {
        return $this->print($message, 'red', $newline);
    }

    public function success(string $message, bool $newline = true) {
        return $this->print($message, 'green', $newline);
    }

    public function prompt($prompt, string $type = null) {
        $type = strtolower($type ?? $prompt->type ?? 'string');

        $nullable = isset($prompt->default);
        $default = $prompt->default ?? null;

        if ($type === 'string' || $type === 'int' || $type === 'bool') {
            return $this->v($type, $nullable, $default)->space(11)->input($prompt->prompt);
        } else if ($type === 'date') {
            $input_format = $prompt->{'date-input-format'} ?? 'm/Y';
            $output_format = $prompt->{'date-output-format'} ?? 'm/Y';

            return $this->v(function(&$input, &$message) use ($input_format, $output_format, $nullable, $default) {
                if ($input === '' && $nullable === true) {
                    $input = $default;
                }

                $d = DateTime::createFromFormat($input_format, $input);

                if ($d && $d->format($input_format) === $input) {
                    $input = $d->format($output_format);

                    return true;
                } else {
                    $message = sprintf('Please enter a date in the format "%s".', $input_format);

                    return false;
                }
            })->space(11)->input($prompt->prompt);
        } else {
            return $this->v('string', $nullable, $default)->space(11)->input($prompt->prompt);
        }
    }

    public function getRenderItems($client, $invoice_number) {
        $total = 0;
        $list = new Collection;
        $items = new Collection;

        $client_items = collect()->wrap($client->items ?? [])->take(10)->values();

        $this->print('Invoice Items:', 'cyan', true);

        foreach ($client_items as $i => $item) {
            $this->print([
                [sprintf("%'. 4d", $i + 1), 'light_green'],
                ' - ',
                [$item->name, 'cyan'],
            ], null, true);

            $title = $item->title ?? '';
            $subtitle = $item->subtitle ?? '';
            $price = $item->price ?? 0;
            $quantity = $item->quantity ?? 0;

            if (!isset($item->title) || !isset($item->subtitle) || !isset($item->price) || !isset($item->quantity)) {
                $this->nl()->space(7)->print('Please fill out the following information:', 'light_gray', true);

                if (!isset($item->title)) {
                    $title = $this->v()->space(12)->input('Title');
                }

                if (!isset($item->subtitle)) {
                    $subtitle = $this->v('string', true)->space(9)->input('Subtitle');
                }

                if (!isset($item->price)) {
                    $price = $this->v('int')->space(12)->input('Price');
                }

                if (!isset($item->quantity)) {
                    $quantity = $this->v('int')->space(9)->input('Quantity');
                }
            }

            if (isset($item->prompts)) {
                $prompts = get_object_vars($item->prompts);

                $searches = [];
                $replacements = [];

                $this->nl()->space(7)->print('Please fill out the following prompts:', 'light_gray', true);

                foreach ($prompts as $name => $prompt) {
                    $replace = $this->prompt($prompt);

                    $searches[] = sprintf('{{ %s }}', $name);
                    $replacements[] = $replace;
                }

                $title = str_replace($searches, $replacements, (string) $title);
                $subtitle = str_replace($searches, $replacements, (string) $subtitle);
                $price = str_replace($searches, $replacements, (string) $price);
                $quantity = str_replace($searches, $replacements, (string) $quantity);
            }

            $price = intval($price);
            $quantity = intval($quantity);

            $total += $price * $quantity;

            $items->push([
                'title' => $title,
                'subtitle' => $subtitle,
                'price' => $price,
                'quantity' => $quantity,
            ]);

            $this->nl();
        }

        while ($items->count() < 10) {
            $add = $this->v('bool', true, false)->input([
                [sprintf("%'. 4d", $items->count() + 1), 'light_green'],
                ' - ',
                'Add an item to invoice? [',
                ['y', 'brown'],
                '/',
                ['N', 'brown'],
                ']'
            ]);

            $this->printBack(1, 40, $add ? 'Yes' : 'No');

            if ($add === true) {
                $this->nl()->space(7)->print('Please fill out the following information:', 'light_gray', true);

                $title = $this->v()->space(12)->input('Title');
                $subtitle = $this->v('string', true)->space(9)->input('Subtitle');
                $price = $this->v('int')->space(12)->input('Price');
                $quantity = $this->v('int')->space(9)->input('Quantity');

                $total += $price * $quantity;

                $items->push([
                    'title' => $title,
                    'subtitle' => $subtitle,
                    'price' => $price,
                    'quantity' => $quantity,
                ]);

                $this->nl();
            } else {
                break;
            }
        }

        $this->nl();

        $height = 270;

        foreach ($items as $item) {
            $list->push([
                'type' => 'text',
                'position' => ['x' => 58, 'y' => $height],
                'text' => $item['title'],
                'font' => [
                    'max_width' => 450 - 58 - 20,
                ],
            ]);

            if (!empty($item['subtitle'])) {
                $list->push([
                    'type' => 'text',
                    'position' => ['x' => 58, 'y' => $height + 15],
                    'text' => $item['subtitle'],
                    'font' => [
                        'size' => 9,
                        'color' => $this->colors->gray,
                        'max_width' => 450 - 58 - 20,
                    ],
                ]);
            }

            $list->push([
                'type' => 'text',
                'position' => ['x' => 450, 'y' => $height],
                'text' => sprintf('$%d', $item['price']),
                'font' => [
                    'max_width' => 65,
                ],
            ], [
                'type' => 'text',
                'position' => ['x' => 612 - 58, 'y' => $height],
                'text' => $item['quantity'],
                'font' => [
                    'align' => 'right',
                    'max_width' => 30,
                ],
            ]);

            $height += 35;
        }

        return [
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
                    'color' => $this->colors->blue,
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
                    'color' => $this->colors->blue,
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
                    'color' => $this->colors->blue,
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
                    'color' => $this->colors->blue,
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
                    'color' => $this->colors->blue,
                ],
            ],

            // Price
            [
                'type' => 'text',
                'position' => ['x' => 450, 'y' => 250],
                'text' => 'Price',
                'font' => [
                    'size' => 9,
                    'color' => $this->colors->blue,
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
                    'color' => $this->colors->blue,
                ],
            ],


            ...$list->all(),


            // Thanks & Footer
            [
                'type' => 'text',
                'position' => ['x' => 58, 'y' => 792 - 58 - 21 - 60],
                'text' => 'It is truly a pleasure to serve you!',
                'font' => [
                    'size' => 9,
                    'color' => $this->colors->blue,
                ],
            ],
            [
                'type' => 'textarea',
                'position' => ['x' => 58, 'y' => 792 - 58 - 21],
                'text' => "Pilot Communications Group\nPO BOX 332, Thompson's Station, TN 37179",
                'font' => [
                    'size' => 9,
                    'line_spacing' => 3,
                    'color' => $this->colors->blue,
                ],
            ],
        ];
    }
}
