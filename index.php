<?php

require __DIR__ . '/vendor/autoload.php';

use App\Pdf\Data\Color;
use App\Pdf\Generator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Wujunze\Colors;

class InvoiceGenerator {
    public function __construct() {
        $this->clearInput();

        if (!file_exists(invoice_path())) {
            mkdir(invoice_path(), 0777, true);
        }

        $this->colors = (object) [
            'blue' => new Color(47, 98, 253),
            'gray' => new Color(145, 156, 158),
        ];

        $this->clients_file = output_path('clients.json');

        $this->loadSettings();

        $client_data = $this->chooseClient();

        $this->createPdf(
            $client_data['client'],
            $client_data['invoice-number']
        );

        $this->saveSettings();
    }

    public function createPdf($client, int $invoice_number) {
        $filename = sprintf('%s-%s.pdf', $client->id, $this->leading($invoice_number, '0', 6));
        $filepath = invoice_path($filename);

        $generator = new Generator;

        $pdf = $generator->render($this->getRenderItems($client, $invoice_number));

        file_put_contents($filepath, $pdf->contents());

        $this->printf('Successfully generated the invoice for %s!', $client->name, 'green')->nl();

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
        $this->clients = file_exists($this->clients_file) ? json_decode(file_get_contents($this->clients_file)) : [];

        return $this;
    }

    public function saveSettings() {
        file_put_contents($this->clients_file, json_encode($this->clients, JSON_PRETTY_PRINT));

        return $this;
    }

    public function chooseClient() {
        $this->print('Clients:', 'cyan')->nl()->printl('0', 4, 'light_green')->sep()->print('Custom', 'cyan')->nl();

        foreach ($this->clients as $i => $client) {
            $this->printl($i + 1, 4, 'light_green')->sep()->print($client->name, 'cyan')->nl();
        }

        $count = count($this->clients);

        $index = $this->nl()->v(['int', function ($input, &$message) use ($count) {
            $message = 'Please select a valid client.';

            return $input >= 0 && $input <= $count;
        }])->input('Select client');

        if ($index === 0) {
            $id = $this->nl()->v(function($input, &$message) {
                $message = 'Please enter a string using only lowercase letters.';

                return ctype_lower($input);
            })->printl('Id', 17, 'cyan')->input();

            $name = $this->v()->printl('Name', 17, 'cyan')->input();

            $billed = $this->v()->printl('Billed To', 17, 'cyan')->input();

            $invoice = $this->v('int', true)->sp(4)->input([
                'Invoice # (',
                ['1', 'brown'],
                ')'
            ]);

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

            $next_invoice = $client->{'next-invoice-number'};

            $count = strlen($next_invoice);

            $this->nl()->printl('Id', 16 + $count, 'cyan')->arrow()->print($client->id)
                 ->nl()->printl('Name', 16 + $count, 'cyan')->arrow()->print($client->name)
                 ->nl()->printl('Billed To', 16 + $count, 'cyan')->arrow()->print($client->{'billed-to'})->nl();

            $invoice = $this->v('int', true)->sp(4)->input([
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

    public function input($message = '', string $color = 'cyan') {
        $this->inputting = true;

        if ($this->input_ongoing) {
            $this->print($this->input_prefix);
        }

        $this->print($message, $color)->arrow();

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
                if (!$this->isTypeSupported($validator)) {
                    $validator = 'string';
                }

                $null = false;

                if ($output === '' && $this->input_nullable) {
                    $output = $this->input_default;

                    $null = true;
                }

                if ($validator === 'string') {
                    if ($output === '' && $null === false) {
                        $this->error('Please enter a value.');

                        return $this->input($message, $color);
                    }
                }

                if (($validator === 'int' || $this->isTypeRange($validator)) && $output !== null) {
                    if (!ctype_digit($output)) {
                        $this->error('Please enter a valid whole number.');

                        return $this->input($message, $color);
                    } else if ($null === false) {
                        $output = intval($output);
                    }
                }

                if ($validator === 'bool') {
                    if (in_array(strtolower($output), ['1', 'y', 'yes', 't', 'true'])) {
                        $output = true;
                    } else if (in_array(strtolower($output), ['0', 'n', 'no', 'f', 'false'])) {
                        $output = false;
                    } else if ($null === false) {
                        $this->error('Please enter a valid yes or no answer.');

                        return $this->input($message, $color);
                    }
                }

                if (preg_match('/^range:\s*(\d+)?\s*,\s*(\d+)?\s*(?:,((?:,,|[^,]*)*))?$/i', $validator, $matches) === 1) {
                    $range = [
                        empty($matches[1]) ? PHP_INT_MIN : intval($matches[1]),
                        empty($matches[2]) ? PHP_INT_MAX : intval($matches[2]),
                    ];

                    sort($range);

                    $error = sprintf('Please enter a valid whole number between %s and %s.', $range[0], $range[1]);

                    if ($range[0] === PHP_INT_MIN) {
                        $error = sprintf('Please enter a valid whole number equal to or below %s.', $range[1]);
                    } else if ($range[1] === PHP_INT_MAX) {
                        $error = sprintf('Please enter a valid whole number equal to or above %s.', $range[0]);
                    }

                    if (isset($matches[3])) {
                        $error = str_replace(',,', ',', trim($matches[3]));
                    }

                    if (($output < $range[0] || $output > $range[1]) && $null === false) {
                        $this->error($error);

                        return $this->input($message, $color);
                    }
                }

                if (preg_match('/^date:((?:,,|[^,]+)+)(?:,((?:,,|[^,]*)*))?(?:,((?:,,|[^,]*)*))?$/i', $validator, $matches) === 1) {
                    $input_format = trim($matches[1]);
                    $output_format = empty(trim($matches[2] ?? '')) ? $input_format : trim($matches[2]);

                    $error = sprintf('Please enter a date in the format "%s".', $input_format);

                    if (isset($matches[3])) {
                        $error = str_replace(',,', ',', trim($matches[3]));
                    }

                    $date = DateTime::createFromFormat($input_format, $output);

                    if ($date && $date->format($input_format) === $output) {
                        $output = $date->format($output_format);
                    } else {
                        $this->error($error);

                        return $this->input($message, $color);
                    }
                }
            }
        }

        $this->clearInput();

        return $output;
    }

    public function isTypeSupported(string $type) {
        return in_array(strtolower($type), ['string', 'int', 'bool']) || $this->isTypeRange($type) || $this->isTypeDate($type);
    }

    public function isTypeRange($type) {
        return preg_match('/^range:\s*(?:\d+,|,\d+|\d+\s*,\s*\d+)\s*(?:,(?:,,|[^,]*)*)?$/i', $type) === 1;
    }

    public function isTypeDate($type) {
        return strlen($type) > 5 && preg_match('/^date:((?:,,|[^,]+)+)(?:,((?:,,|[^,]*)*))?(?:,((?:,,|[^,]*)*))?$/i', $type) === 1;
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

    public function print($message = null, string $color = null) {
        if (is_array($message)) {
            foreach ($message as $part) {
                if (is_array($part)) {
                    $this->print(...$part);
                } else {
                    $this->print($part, $color);
                }
            }
        } else {
            if ($message === null) {
                $message = PHP_EOL;
            } else {
                $message = Colors::initColoredString($message, $color);
            }

            if ($this->input_ongoing && !$this->inputting) {
                $this->input_prefix .= $message;
            } else {
                fwrite(STDOUT, $message);
            }
        }

        return $this;
    }

    public function printf(string $message, $parts = [], string $color = null) {
        return $this->print(sprintf($message, ...Arr::wrap($parts)), $color);
    }

    public function printl(string $message, int $count, string $color = null) {
        return $this->print($this->leading($message, ' ', $count), $color);
    }

    public function printBack($rows, $cols, $message, string $color = null) {
        return $this->printf("\033[s\033[%dA\033[%dC%s\033[u", [$rows, $cols, $message], $color);
    }

    public function cls() {
        return $this->print("\033[H\033[J");
    }

    public function nl(int $count = 1) {
        return $this->print(str_repeat(PHP_EOL, $count));
    }

    public function sp(int $count = 1) {
        return $this->print(str_repeat(' ', $count));
    }

    public function error(string $message) {
        return $this->print($message, 'red')->nl();
    }

    public function success(string $message) {
        return $this->print($message, 'green')->nl();
    }

    public function notice(string $message) {
        return $this->print($message, 'brown')->nl();
    }

    public function arrow() {
        return $this->print(' >> ', 'dark_gray');
    }

    public function sep() {
        return $this->print(' - ', 'dark_gray');
    }

    public function leading($value, $filler, $count) {
        return sprintf("%'.{$filler}{$count}s", $value);
    }

    public function money($value) {
        return '$' . $value;
    }

    public function prompt($prompt, string $type = null) {
        $type = $type ?? $prompt->type ?? 'string';

        return $this->v(
            $this->isTypeSupported($type) ? $type : 'string',
            isset($prompt->default),
            $prompt->default ?? null
        )->sp(11)->input($prompt->prompt);
    }

    public function getRenderItems($client, $invoice_number) {
        $total = 0;
        $list = new Collection;
        $items = new Collection;

        $client_items = collect()->wrap($client->items ?? [])->take(10)->values();

        $this->print('Invoice Items:', 'cyan')->nl();

        foreach ($client_items as $i => $item) {
            $this->printl($i + 1, 4, 'light_green')->sep()->print($item->name, 'cyan')->nl();

            $title = $item->title ?? '';
            $subtitle = $item->subtitle ?? '';
            $price = $item->price ?? 0;
            $quantity = $item->quantity ?? 0;

            if (!isset($item->title) || !isset($item->subtitle) || !isset($item->price) || !isset($item->quantity)) {
                $this->nl()->sp(7)->notice('Please fill out the following information:');

                if (!isset($item->title)) {
                    $title = $this->v()->printl('Title', 17, 'cyan')->input();
                }

                if (!isset($item->subtitle)) {
                    $subtitle = $this->v('string', true)->printl('Subtitle', 17, 'cyan')->input();
                }

                if (!isset($item->price)) {
                    $price = $this->v('int')->printl('Price', 17, 'cyan')->input();
                }

                if (!isset($item->quantity)) {
                    $quantity = $this->v('int')->printl('Quantity', 17, 'cyan')->input();
                }
            }

            if (isset($item->prompts)) {
                $prompts = get_object_vars($item->prompts);

                $searches = [];
                $replacements = [];

                $this->nl()->sp(7)->notice('Please fill out the following prompts:');

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

            $this->nl()->sp(7)->notice('Item Review:')
                 ->printl('Title', 17, 'cyan')->arrow()->print($title)->nl();

            if (!empty($subtitle)) {
                $this->printl('Subtitle', 17, 'cyan')->arrow()->print($subtitle)->nl();
            }

            $this->printl('Price', 17, 'cyan')->arrow()->print($price)->nl()
                 ->printl('Quantity', 17, 'cyan')->arrow()->print($quantity)->nl(2);
        }

        while ($items->count() < 10) {
            $add = $this->v('bool', true, false)->printl($items->count() + 1, 4, 'light_green')->sep()->input([
                'Add an item to the invoice? [',
                ['y', 'brown'],
                '/',
                ['N', 'brown'],
                ']'
            ]);

            $this->printBack(1, 44, $add ? 'Yes' : 'No');

            if ($add === true) {
                $this->nl()->sp(7)->notice('Please fill out the following information:');

                $title = $this->v()->printl('Title', 17, 'cyan')->input();
                $subtitle = $this->v('string', true)->printl('Subtitle', 17, 'cyan')->input();
                $price = $this->v('int')->printl('Price', 17, 'cyan')->input();
                $quantity = $this->v('int')->printl('Quantity', 17, 'cyan')->input();

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
                'text' => $this->money($item['price']),
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
                'text' => $this->leading($invoice_number, '0', 6),
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
                'text' => $this->money($total),
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

new InvoiceGenerator;
