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

        $this->load();

        $this->cls()->startProgram();

        $this->save();
    }

    public function startProgram() {
        $this->notice('Actions:');

        foreach (['Create Invoice', 'Create Client', 'Edit Client'] as $i => $a) {
            $this->list($i + 1)->print($a, 'cyan')->nl();
        }

        $this->nl()->v('range:1,3,Please select a valid action.', true, 1, true)
             ->inputv($action, ['Select an action (', ['1', 'brown'], ')'])->nl(2);

        if ($action === 1) {
            $this->createInvoice();
        } else if ($action === 2) {
            $this->createClient();
        } else if ($action === 3) {
            $this->editClient();
        }

        $this->nl(2)->startProgram();
    }

    public function editClient() {
        $this->notice('Client List:');

        foreach ($this->clients as $i => $client) {
            $this->list($i + 1)->print($client->name, 'cyan')->nl();
        }

        $this->nl()->v(sprintf('range:1,%d,Please select a valid client.', count($this->clients)))
             ->inputv($index, 'Select a client to edit')->nl(2);

        $client = $this->clients[$index - 1];

        $this->notice('Selected Client:')
             ->printl('Id', 13, 'cyan')->arrow()->print($client->id)->nl()
             ->printl('Name', 13, 'cyan')->arrow()->print($client->name)->nl()
             ->printl('Billed To', 13, 'cyan')->arrow()->print($client->{'billed-to'})->nl()
             ->printl('Invoice #', 13, 'cyan')->arrow()->print($client->{'next-invoice-number'})->nl();

        $action = null;

        while ($action !== 0) {
            $this->nl(2)->notice('Client Actions:')
                 ->list(0)->print('Back', 'cyan')->nl();

            foreach (['Edit Invoice Items', 'Edit Client Settings', 'Delete Client'] as $i => $a) {
                $this->list($i + 1)->print($a, 'cyan')->nl();
            }

            $this->nl()->v('range:0,3,Please select a valid action.')->inputv($action, 'Select a client action');

            if ($action === 1) {
                $this->nl()->editInvoiceItems($client);
            } else if ($action === 2) {
                $this->nl(2)->print(['Editing Client Settings (', ['Leave blank to keep old settings', 'cyan'], '):'], 'brown')->nl()
                     ->v('string:lower', true, $client->id, true)->printl('Id', 13, 'cyan')->inputv($client->id)
                     ->v('string', true, $client->name, true)->printl('Name', 13, 'cyan')->inputv($client->name)
                     ->v('string', true, $client->{'billed-to'}, true)->printl('Billed To', 13, 'cyan')->inputv($client->{'billed-to'})
                     ->v('int', true, $client->{'next-invoice-number'}, true)->printl('Invoice #', 13, 'cyan')->inputv($client->{'next-invoice-number'})
                     ->save()
                     ->nl()->printf('Edited the client "%s".', $client->name, 'green')->nl();
            } else if ($action === 3) {
                $this->nl()->v('bool', true, false, ['No', 'Yes'])->inputv(
                    $confirm,
                    ['Are you sure you want to delete this client? [', ['y', 'brown'], '/', ['N', 'brown'], ']']
                )->nl();

                if ($confirm) {
                    array_splice($this->clients, $index - 1, 1);

                    $this->save();

                    $this->printf('Deleted the client "%s".', $client->name, 'red')->nl();

                    break;
                } else {
                    $this->printf('Canceled the deletion of the client "%s".', $client->name, 'green')->nl();
                }
            }
        }
    }

    public function createClient() {
        $this->notice('Creating New Client')
             ->v('string:lower')->printl('Id', 17, 'cyan')->inputv($id)
             ->v()->printl('Name', 17, 'cyan')->inputv($name)
             ->v()->printl('Billed To', 17, 'cyan')->inputv($billed)
             ->v('int', true, 1, true)->sp(4)->inputv($invoice, ['Invoice # (', ['1', 'brown'], ')'])->nl()
             ->v('bool', true, true, ['No', 'Yes'])
             ->inputv($confirm, ['Are you sure you want to create this client? [', ['Y', 'brown'], '/', ['n', 'brown'], ']'])->nl();

        if ($confirm) {
            array_push($this->clients, (object) [
                'id' => $id,
                'name' => $name,
                'billed-to' => $billed,
                'next-invoice-number' => $invoice,
                'items' => [],
            ]);

            $this->save();

            $this->printf('Successfully created the client "%s"!', $name, 'green')->nl();
        } else {
            $this->printf('Canceled the creation of the client "%s".', $name, 'red')->nl();
        }
    }

    public function createInvoice() {
        $this->notice('Client List:')
             ->list(0)->print('Custom', 'cyan')->nl();

        foreach ($this->clients as $i => $client) {
            $this->list($i + 1)->print($client->name, 'cyan')->nl();
        }

        $this->nl()->v(sprintf('range:0,%d,Please select a valid client.', count($this->clients)))
             ->inputv($index, 'Select a client')->nl(2);

        $client = null;
        $invoice = null;

        if ($index === 0) {
            $this->notice('Creating Custom Client for Invoice')
                 ->v('string:lower')->printl('Id', 17, 'cyan')->inputv($id)
                 ->v()->printl('Name', 17, 'cyan')->inputv($name)
                 ->v()->printl('Billed To', 17, 'cyan')->inputv($billed)
                 ->v('int', true, 1, true)->sp(4)->inputv($invoice, ['Invoice # (', ['1', 'brown'], ')'])->nl(2);

            $client = (object) [
                'id' => $id,
                'name' => $name,
                'billed-to' => $billed,
                'items' => [],
            ];
        } else {
            $client = $this->clients[$index - 1];

            $next_invoice = $client->{'next-invoice-number'};

            $count = strlen($next_invoice);

            $this->notice('Using Existing Client for Invoice')
                 ->nl()->printl('Id', 16 + $count, 'cyan')->arrow()->print($client->id)
                 ->nl()->printl('Name', 16 + $count, 'cyan')->arrow()->print($client->name)
                 ->nl()->printl('Billed To', 16 + $count, 'cyan')->arrow()->print($client->{'billed-to'})->nl()
                 ->v('int', true, $next_invoice, true)->sp(4)->inputv($invoice, ['Invoice # (', [$next_invoice, 'brown'], ')'])->nl(2);

            if ($invoice === $next_invoice) {
                $client->{'next-invoice-number'}++;
            }
        }

        $filename = sprintf('%s-%s.pdf', $client->id, $this->leading($invoice, '0', 6));
        $filepath = invoice_path($filename);

        $generator = new Generator;

        $pdf = $generator->render($this->getRenderItems($client, $invoice));

        file_put_contents($filepath, $pdf->contents());

        $this->printf('Successfully generated an invoice for the client "%s"!', $client->name, 'green')->nl(2)
             ->v('bool', true, false, ['No', 'Yes'])
             ->inputv($open, ['Do you want to open the invoice? [', ['y', 'brown'], '/', ['N', 'brown'], ']'])
             ->command($open ? 'start "" "%s"' : 'explorer.exe /select, "%s"', $filepath);
    }

    public function load() {
        $this->clients = file_exists($this->clients_file) ? json_decode(file_get_contents($this->clients_file)) : [];

        return $this;
    }

    public function save() {
        file_put_contents($this->clients_file, json_encode($this->clients, JSON_PRETTY_PRINT));

        return $this;
    }

    public function v($validator = 'string', $nullable = false, $default = null, $printback = false) {
        $this->inputting = false;
        $this->input_ongoing = true;
        $this->input_validator = $validator;
        $this->input_nullable = $nullable;
        $this->input_default = $default;
        $this->input_printback = $printback;
        $this->input_prefix = '';

        return $this;
    }

    public function clearInput() {
        $this->inputting = false;
        $this->input_ongoing = false;
        $this->input_validator = 'string';
        $this->input_nullable = false;
        $this->input_default = null;
        $this->input_printback = false;
        $this->input_prefix = '';

        return $this;
    }

    public function inputv(&$variable = null, $message = '', string $color = 'cyan') {
        $variable = $this->input($message, $color);

        return $this;
    }

    public function input($message = '', string $color = 'cyan') {
        $this->print($message, $color);

        $this->inputting = true;

        if ($this->input_ongoing) {
            $this->print($this->input_prefix);

            $message = '';
        }

        $this->arrow();

        $stdin = fgets(STDIN);

        if ($stdin === false) {
            die; // Ctrl+C
        }

        $output = trim($stdin);

        $validators = Arr::wrap($this->input_ongoing ? $this->input_validator : 'string');

        foreach ($validators as $validator) {
            if (is_callable($validator)) {
                $error = '';

                if (call_user_func_array($validator, [&$output, &$error]) === false) {
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

                if ($validator === 'string' || $validator === 'string:lower') {
                    if ($output === '' && $null === false) {
                        $this->error('Please enter a value.');

                        return $this->input($message, $color);
                    } else if ($validator === 'string:lower' && !ctype_lower($output)) {
                        $this->error('Please enter a string using only lowercase letters.');

                        return $this->input($message, $color);
                    }
                }

                if (($validator === 'int' || $this->isTypeRange($validator)) && $output !== null) {
                    if (!is_int($output) && !ctype_digit($output)) {
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

        if ($this->input_printback !== false) {
            $print = $output ?? '';

            if (is_callable($this->input_printback)) {
                $print = call_user_func($this->input_printback, $output);
            } else if (is_array($this->input_printback)) {
                $print = $this->input_printback[$print] ?? $print;
            }

            $this->printBack(1, $this->getInputWhitespace() + 4, $print);
        }

        $this->clearInput();

        return $output;
    }

    public function getInputWhitespace() {
        $lines = explode(PHP_EOL, $this->strip($this->input_prefix));

        return strlen(trim(end($lines), "\r\n"));
    }

    public function isTypeSupported(string $type) {
        return in_array(strtolower($type), ['string', 'string:lower', 'int', 'bool'])
            || $this->isTypeRange($type)
            || $this->isTypeDate($type);
    }

    public function isTypeRange($type) {
        return preg_match('/^range:\s*(?:\d+,|,\d+|\d+\s*,\s*\d+)\s*(?:,(?:,,|[^,]*)*)?$/i', $type) === 1;
    }

    public function isTypeDate($type) {
        return strlen($type) > 5 && preg_match('/^date:((?:,,|[^,]+)+)(?:,((?:,,|[^,]*)*))?(?:,((?:,,|[^,]*)*))?$/i', $type) === 1;
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
                $message = $this->color($message, $color);
            }

            if ($this->input_ongoing && !$this->inputting) {
                $this->input_prefix .= $message;
            } else {
                fwrite(STDOUT, $message);
            }
        }

        return $this;
    }

    public function color(string $message, string $color = null) {
        return Colors::initColoredString($message, $color);
    }

    public function strip(string $message) {
        return preg_replace("/\033\[\d+(;\d+)?m/", '', $message);
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
        if ($this->input_ongoing) {
            $this->sp($this->getInputWhitespace())->arrow('red');
        }

        return $this->print($message, 'red')->nl();
    }

    public function success(string $message) {
        return $this->print($message, 'green')->nl();
    }

    public function notice(string $message) {
        return $this->print($message, 'brown')->nl();
    }

    public function arrow(string $color = 'dark_gray') {
        return $this->print(' >> ', $color);
    }

    public function sep(string $color = 'dark_gray') {
        return $this->print(' - ', $color);
    }

    public function list(int $index, string $prefix = '') {
        return $this->printl($prefix . $index, 4, 'light_green')->sep();
    }

    public function leading($value, $filler, $count) {
        if (str_contains($value, "\033")) {
            $strip = $this->strip($value);

            return str_replace($strip, str_repeat($filler, max(0, $count - strlen($strip))) . $strip, $value);
        }

        return sprintf("%'.{$filler}{$count}s", $value);
    }

    public function money($value) {
        return '$' . $value;
    }

    public function command(string $command, ...$values) {
        @exec(sprintf($command, ...$values));

        return $this;
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

        $this->notice('Invoice Items:');

        foreach ($client_items as $i => $item) {
            $this->list($i + 1, '#')->print($item->name, 'cyan')->nl();

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
                    $price = $this->v('int', false, null, [$this, 'money'])->printl('Price', 17, 'cyan')->input();
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

                    $searches[] = sprintf('<<%s>>', $name);
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

            $this->printl('Price', 17, 'cyan')->arrow()->print($this->money($price))->nl()
                 ->printl('Quantity', 17, 'cyan')->arrow()->print($quantity)->nl(2);
        }

        while ($items->count() < 10) {
            $default = false;
            $printed_defaults = [['y', 'brown'], '/', ['N', 'brown']];

            if ($items->count() === 0) {
                $default = true;
                $printed_defaults = [['Y', 'brown'], '/', ['n', 'brown']];
            }

            $this->v('bool', true, $default, ['No', 'Yes'])->list($items->count() + 1, '#')
                 ->inputv($add, ['Add an item to the invoice? [', ...$printed_defaults, ']']);

            if ($add === true) {
                $this->nl()->sp(7)->notice('Please fill out the following information:')
                     ->v()->printl('Title', 17, 'cyan')->inputv($title)
                     ->v('string', true)->printl('Subtitle', 17, 'cyan')->inputv($subtitle)
                     ->v('int', false, null, [$this, 'money'])->printl('Price', 17, 'cyan')->inputv($price)
                     ->v('int')->printl('Quantity', 17, 'cyan')->inputv($quantity);

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
