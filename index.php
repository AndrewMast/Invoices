<?php

require __DIR__ . '/vendor/autoload.php';

use App\Pdf\Data\Color;
use App\Pdf\Generator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Wujunze\Colors;

class Menu {
    public function __construct($parent, $title, $prompt = null, $error = null) {
        if ($parent instanceof Program) {
            $this->parent = null;
            $this->program = $parent;
        } else if ($parent instanceof self) {
            $this->parent = $parent;
            $this->program = $parent->program;
        }

        $this->title = $title;
        $this->prompt = $prompt ?? $title;
        $this->error = $error ?? 'Invalid option.';
        $this->items = [];
        $this->default = false;

        $this->on_showing = null;
    }

    public function items(...$items) {
        array_push($this->items, ...$items);

        return $this;
    }

    public function item(MenuItem $item) {
        array_push($this->items, $item);

        return $this;
    }

    public function default($default = false) {
        $this->default = $default;

        return $this;
    }

    public function showing($on_showing = null) {
        $this->on_showing = $on_showing;

        return $this;
    }

    public function show() {
        if ($this->on_showing !== null && is_callable($this->on_showing)) {
            call_user_func($this->on_showing, $this);
        }

        $this->clear()->print($this->title, 'brown')->print(':', 'brown')->nl()
             ->printl('0', 4, 'light_green')->sep()->print('Exit', 'red')->nl();

        foreach ($this->items as $i => $item) {
            $this->printl($i + 1, 4, 'light_green')->sep()->print($item->message, 'cyan')->nl();
        }

        $range = sprintf(
            'range:%d,%d,%s',
            0,
            count($this->items),
            $this->error
        );

        $this->nl()->input($range, $this->default !== false, $this->default, true)
             ->message($this->prompt)
             ->message($this->default === false ? [] : [' (', [$this->default, 'brown'], ')'])
             ->set($index)->nl();

        if ($index === 0) {
            if ($this->parent === null) {
                $this->program->exit();
            } else {
                $this->parent->show();
            }
        } else {
            $this->items[$index - 1]->call();
        }
    }

    public function __call($name, $arguments) {
        if (in_array($name, ['input'])) {
            return $this->program->{$name}(...$arguments);
        } else if (method_exists($this->program->output, $name)) {
            $output = $this->program->output->{$name}(...$arguments);

            return $output === $this->program->output ? $this : $output;
        }

        throw new \Exception("Unknown method '$name'");
    }
}

class MenuItem {
    public function __construct($message, $action) {
        $this->message = $message;
        $this->action = $action;
    }

    public function call() {
        if ($this->action instanceof Menu) {
            return $this->action->show();
        } else if (is_callable($this->action)) {
            call_user_func($this->action);
        } else {
            throw new \Exception('Invalid action!');
        }
    }
}

class Program {
    public function __construct() {
        $this->input = null;
        $this->output = new Output($this);

        $this->load();

        $menu = new Menu($this, 'Select Client');

        $menu->showing(function(Menu $menu) {
            $menu->items = [];

            $menu->item(new MenuItem([['Create new client', 'green']], $this->createNewClientMenu($menu)));

            foreach ($this->clients as $client) {
                $menu->item(new MenuItem($client->name, $this->createClientMenu($menu, $client)));
            }
        });

        $menu->show();
    }

    public function createNewClientMenu(Menu $menu) {
        return function() use ($menu) {
            $this->clear()->print('Creating new client:', 'brown')->nl()
                 ->input('string:lower')->sp(4)->message('Id')->set($id)
                 ->input()->sp(4)->message('Name')->set($name)
                 ->input()->sp(4)->message('Billed To')->set($billed)
                 ->input('int', true, 1, true)->sp(4)->message(['Invoice # (', ['1', 'brown'], ')'])->set($invoice)
                 ->input('path', true, null, true)->sp(4)->message(['Storage Path (', ['optional', 'gray'], ')'])->set($path)
                 ->nl()->input('bool', true, true, ['No', 'Yes'])
                 ->message(['Are you sure you want to create this client? [', ['Y', 'brown'], '/', ['n', 'brown'], ']'])
                 ->set($confirm)->nl();

            if ($confirm) {
                $this->clients->push((object) [
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

            sleep(3);

            $menu->show();
        };
    }

    public function createClientMenu(Menu $parent, $client) {
        $menu = new Menu($parent, ['Actions for ', [$client->name, 'green']], 'Select action', 'Please select a valid action');

        $next = $client->{'next-invoice-number'};

        $menu->items(
            new MenuItem(['Create Next Invoice (', [$next, 'brown'], ')'], $this->createInvoiceMenu($menu, $client, $next)),
            new MenuItem(['Create Custom Invoice'], $this->createInvoiceMenu($menu, $client)),
            new MenuItem(['Edit Client'], $this->createEditClientMenu($menu, $client))
        );

        $menu->default(1);

        return $menu;
    }

    public function createInvoiceMenu(Menu $parent, $client, $next = null) {
        //
    }

    public function createEditClientMenu(Menu $parent, $client) {
        //
    }

    public function load() {
        if (!file_exists(invoice_path())) {
            mkdir(invoice_path(), 0777, true);
        }

        $this->clients_file = output_path('clients.json');

        $this->clients = new Collection(file_exists($this->clients_file) ? json_decode(file_get_contents($this->clients_file)) : []);
    }

    public function save() {
        file_put_contents($this->clients_file, json_encode($this->clients->all(), JSON_PRETTY_PRINT));

        return $this;
    }

    public function exit() {
        $this->print('Exiting...', 'red')->nl();

        die;
    }

    public function input($validator = 'string', $nullable = false, $default = null, $printback = false) {
        return $this->input = new Input($this, $validator, $nullable, $default, $printback);
    }

    public function clearInput() {
        $this->input = null;

        return $this;
    }

    public function __call($name, $arguments) {
        if (method_exists($this->output, $name)) {
            $this->output->{$name}(...$arguments);

            return $this;
        }

        throw new \Exception("Unknown method '$name'");
    }
}

class Input {
    public function __construct(Program $program, $validator = 'string', $nullable = false, $default = null, $printback = false) {
        $this->program = $program;

        $this->inputting = false;
        $this->validator = $validator;
        $this->nullable = $nullable;
        $this->default = $default;
        $this->printback = $printback;
        $this->message = '';
    }

    public function __call($name, $arguments) {
        if (method_exists($this->program->output, $name)) {
            $output = $this->program->output->{$name}(...$arguments);

            return $output === $this->program->output ? $this : $output;
        }

        throw new \Exception("Unknown method '$name'");
    }

    public function message($message, string $color = 'cyan') {
        return $this->print($message, $color);
    }

    public function set(&$variable = null) {
        $variable = $this->get();

        return $this->program;
    }

    public function get() {
        $this->inputting = true;

        $this->print($this->message);

        $this->arrow();

        $stdin = fgets(STDIN);

        if ($stdin === false) {
            die; // Ctrl+C
        }

        $output = trim($stdin);

        $validators = Arr::wrap($this->validator);

        if (str_contains($output, chr(27))) {
            $this->error('Please try that again.');

            return $this->get();
        }

        foreach ($validators as $validator) {
            if (is_callable($validator)) {
                $error = '';

                if (call_user_func_array($validator, [&$output, &$error]) === false) {
                    $this->error($error);

                    return $this->get();
                }
            } else if (is_string($validator)) {
                if (!$this->isTypeSupported($validator)) {
                    $validator = 'string';
                }

                $null = false;

                if ($this->nullable && (str_contains($output, chr(24)) || $output === '')) {
                    $output = $this->default;

                    $null = true;
                }

                if ($validator === 'string' || $validator === 'string:lower') {
                    if ($output === '' && $null === false) {
                        $this->error('Please enter a value.');

                        return $this->get();
                    } else if ($validator === 'string:lower' && !ctype_lower($output)) {
                        $this->error('Please enter a string using only lowercase letters.');

                        return $this->get();
                    }
                }

                if (($validator === 'int' || $this->isTypeRange($validator)) && $output !== null) {
                    if (!is_int($output) && !ctype_digit($output)) {
                        $this->error('Please enter a valid whole number.');

                        return $this->get();
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

                        return $this->get();
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

                        return $this->get();
                    }
                }

                if (preg_match('/^date:((?:,,|[^,]+)+)(?:,((?:,,|[^,]*)*))?(?:,((?:,,|[^,]*)*))?$/i', $validator, $matches) === 1) {
                    $input_format = trim($matches[1]);
                    $output_format = empty(trim($matches[2] ?? '')) ? $input_format : trim($matches[2]);

                    $error = sprintf('Please enter a date in the format "%s".', $input_format);

                    if (isset($matches[3])) {
                        $error = $this->typeUnescape(trim($matches[3]));
                    }

                    $date = DateTime::createFromFormat($input_format, $output);

                    if ($date && $date->format($input_format) === $output) {
                        $output = $date->format($output_format);
                    } else {
                        $this->error($error);

                        return $this->get();
                    }
                }

                if (preg_match('/^array:\s*(i|s)\s*,\s*((?:,,|[^,]*)*)\s*,\s*(.*)\s*$/i', $validator, $matches) === 1) {
                    $settings = strtolower(trim($matches[1]));
                    $error = $this->typeUnescape(trim($matches[2]));

                    if (empty($error)) {
                        $error = 'Please select an item in the array.';
                    }

                    $array_string = trim($matches[3]);

                    if (str_contains($settings, 'i')) {
                        $array_string = strtolower($array_string);
                        $output = strtolower($output);
                    }

                    $array = array_map('trim', explode(',', $array_string));

                    if (!in_array($output, $array)) {
                        $this->error($error);

                        return $this->get();
                    }
                }
            }
        }

        if ($this->printback !== false) {
            $print = $output ?? '';

            if (is_callable($this->printback)) {
                $print = call_user_func($this->printback, $output);
            } else if (is_array($this->printback)) {
                $print = $this->printback[$print] ?? $print;
            }

            $this->printBack(1, $this->getWhitespaceCount() + 4, $print);
        }

        $this->program->clearInput();

        return $output;
    }

    public function getWhitespaceCount() {
        $lines = explode(PHP_EOL, $this->strip($this->message));

        return strlen(trim(end($lines), "\r\n"));
    }

    public static function isTypeSupported(string $type) {
        return in_array(strtolower($type), ['string', 'string:lower', 'int', 'bool'])
            || static::isTypeRange($type)
            || static::isTypeDate($type)
            || static::isTypeArray($type);
    }

    public static function isTypeRange($type) {
        return preg_match('/^range:\s*(?:\d+,|,\d+|\d+\s*,\s*\d+)\s*(?:,(?:,,|[^,]*)*)?$/i', $type) === 1;
    }

    public static function isTypeDate($type) {
        return strlen($type) > 5 && preg_match('/^date:((?:,,|[^,]+)+)(?:,((?:,,|[^,]*)*))?(?:,((?:,,|[^,]*)*))?$/i', $type) === 1;
    }

    public static function isTypeArray($type) {
        return preg_match('/^array:\s*(i|s)\s*,\s*((?:,,|[^,]*)*)\s*,\s*(.*)\s*$/i', $type) === 1;
    }

    public static function typeEscape(string $message) {
        return str_replace(',', ',,', $message);
    }

    public static function typeUnescape(string $message) {
        return str_replace(',,', ',', $message);
    }
}

class Output {
    public function __construct(Program $program) {
        $this->program = $program;
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

            if ($this->program->input !== null && !$this->program->input->inputting) {
                $this->program->input->message .= $message;
            } else {
                fwrite(STDOUT, $message);
            }
        }

        return $this;
    }

    public function color($message, string $color = null) {
        if (is_array($message)) {
            return array_map(function($m) use ($color) {
                $this->color($m, $color);
            }, $message);
        }

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
        return $this->printf("\0337\033[%dA\033[%dC%s\033[K\0338", [$rows, $cols, $message], $color);
    }

    public function clear() {
        return $this->print("\033[H\033[J");
    }

    public function nl(int $count = 1) {
        return $this->print(str_repeat(PHP_EOL, $count));
    }

    public function sp(int $count = 1) {
        return $this->print(str_repeat(' ', $count));
    }

    public function error(string $message) {
        if ($this->program->input !== null) {
            $this->sp($this->program->input->getWhitespaceCount())->arrow('red');
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

    public function listItem($index, string $prefix = '') {
        return $this->printl($prefix . $index, 4, 'light_green')->sep();
    }

    public function list(string $title, $prompt, string $error, array $actions, $default = false, $zero_action = false) {
        $this->notice($title);

        if ($zero_action !== false) {
            $this->listItem(0)->print($zero_action, 'cyan')->nl();
        }

        $is_assoc = Arr::isAssoc($actions);

        foreach ($actions as $i => $a) {
            $this->listItem($is_assoc ? $i : $i + 1)->print($a, 'cyan')->nl();
        }

        $this->nl()->v(sprintf(
            'range:%d,%d,%s',
            $zero_action === false ? 1 : 0,
            count($actions),
            $error
        ), $default !== false, $default, true)->inputv($index, $prompt);

        return $index;
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
}

class InvoiceGenerator {
    public function __construct() {
        $this->clear()->startProgram();
    }

    public function startProgram() {
        $action = $this->list(
            'Actions:',
            ['Select an action (', ['1', 'brown'], ')'],
            'Please select a valid action.',
            ['Create Invoice', 'Create Client', 'Edit Client'],
            1
        );

        $this->nl(2);

        if ($action === 1) {
            $this->createInvoice();
        } else if ($action === 2) {
            $this->createClient();
        } else if ($action === 3) {
            $this->editClients();
        }

        $this->nl(2)->startProgram();
    }

    public function editClients() {
        $index = $this->list(
            'Client List:',
            'Select a client to edit',
            'Please select a valid client.',
            $this->clients->map->name->all()
        );

        $client = $this->clients[$index - 1];

        $this->nl(2)->notice('Selected Client:')
             ->printl('Id', 13, 'cyan')->arrow()->print($client->id)->nl()
             ->printl('Name', 13, 'cyan')->arrow()->print($client->name)->nl()
             ->printl('Billed To', 13, 'cyan')->arrow()->print($client->{'billed-to'})->nl()
             ->printl('Invoice #', 13, 'cyan')->arrow()->print($client->{'next-invoice-number'})->nl();

        $this->nl(2)->editClient($index);
    }

    public function editClient($index) {
        $client = $this->clients[$index - 1];

        $action = $this->list(
            'Client Actions:',
            'Select a client action',
            'Please select a valid action.',
            [
                'Edit Invoice Items',
                'Edit Client Settings',
                $this->color('Delete Client', 'purple'),
            ],
            false,
            $this->color('Exit Client Actions', 'red')
        );

        if ($action === 1) {
            $this->nl(2)->editInvoiceItems($client);
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
                $this->clients->splice($index - 1, 1);

                $this->save();

                $this->printf('Deleted the client "%s".', $client->name, 'red')->nl();

                return;
            } else {
                $this->printf('Canceled the deletion of the client "%s".', $client->name, 'green')->nl();
            }
        }

        if ($action !== 0) {
            $this->nl(2)->editClient($index);
        }
    }

    public function editInvoiceItems($client) {
        if (!isset($client->items)) {
            $client->items = [];
        }

        $client_items = collect()->wrap($client->items);

        $action = $this->list(
            'Edit Invoice Items:',
            'Select an invoice item',
            'Please select a valid invoice item.',
            array_merge(
                [$this->color('Create new item', 'green')],
                $client_items->take(10)->map->name->all(),
                $this->color($client_items->splice(11)->map->name->all(), 'light_red')
            ),
            1,
            $this->color('Exit Invoice Editor', 'red')
        );

        if ($action === 0) {
            return;
        } else if ($action === 1) {
            $item = (object) [
                'name' => null,
                'title' => null,
                'subtitle' => null,
                'price' => null,
                'quantity' => null,
            ];

            $this->nl(2)->notice('Creating New Invoice Item')
                 ->v()->printl('Name', 17, 'cyan')->inputv($item->name)
                 ->v()->printl('Title', 17, 'cyan')->inputv($item->title)
                 ->v('string', true)->printl('Subtitle', 17, 'cyan')->inputv($item->subtitle)
                 ->v()->printl('Price', 17, 'cyan')->inputv($item->price)
                 ->v('string', true, '1', true)->printl('Quantity', 17, 'cyan')->inputv($item->quantity)
                 ->v('bool', true, true, ['No', 'Yes'])
                 ->inputv($confirm, ['Are you sure you want to create this invoice item? [', ['Y', 'brown'], '/', ['n', 'brown'], ']'])->nl();

            $props = ['name', 'title', 'subtitle', 'price', 'quantity'];

            foreach ($props as $key) {
                $value = trim($item->{$key});

                if (ctype_digit($value)) {
                    $value = intval($value);
                } else if (is_numeric($value)) {
                    $value = floatval($value);
                }

                $item->{$key} = $value;
            }

            if (empty($item->subtitle)) {
                unset($item->subtitle);
            }

            if ($confirm) {
                array_push($client->items, $item);

                $this->save();

                $this->printf('Successfully created the invoice item "%s"!', $item->name, 'green')->nl();
            } else {
                $this->printf('Canceled the creation of the invoice item "%s".', $item->name, 'red')->nl();
            }
        } else {
            $index = $action - 2;

            $this->nl(2)->editInvoiceItem($client, $index);

            $this->save();
        }

        $this->nl(2)->editInvoiceItems($client);
    }

    public function editInvoiceItem($client, $index) {
        $item = $client->items[$index];

        $prompts = isset($item->prompts) ? get_object_vars($item->prompts) : [];

        $action = $this->list(
            'Edit Invoice Item Part:',
            'Select a part',
            'Please select a valid invoice item part.',
            [
                $this->color('Delete invoice item', 'purple'),
                ['Name ', ['(', 'purple'], [$item->name, 'gray'], [')', 'purple']],
                ['Title ', ['(', 'purple'], [$item->title, 'gray'], [')', 'purple']],
                ['Subtitle ', ['(', 'purple'], [$item->subtitle ?? '', 'gray'], [')', 'purple']],
                ['Price ', ['(', 'purple'], [$item->price, 'gray'], [')', 'purple']],
                ['Quantity ', ['(', 'purple'], [$item->quantity, 'gray'], [')', 'purple']],
                ['Prompts ', ['(', 'purple'], [count($prompts) . ' count', 'gray'], [')', 'purple']],
            ],
            0,
            $this->color('Exit Invoice Item Editor', 'red')
        );

        if ($action === 1) {
            $this->nl()->v('bool', true, false, ['No', 'Yes'])->inputv(
                $confirm,
                ['Are you sure you want to delete this invoice item? [', ['y', 'brown'], '/', ['N', 'brown'], ']']
            )->nl();

            if ($confirm) {
                array_splice($client->items, $index - 2, 1);

                $this->save();

                $this->printf('Deleted the invoice item "%s".', $item->name, 'red')->nl();

                return;
            } else {
                $this->printf('Canceled the deletion of the invoice item "%s".', $item->name, 'green')->nl();
            }
        } else if ($action >= 2 && $action <= 6) {
            $props = ['name', 'title', 'subtitle', 'price', 'quantity'];
            $prop = $props[$action - 2];
            $value = $item->{$prop} ?? '';

            $this->nl(2)->printf('Current %s', $prop, 'cyan')->arrow()->print($value)
                 ->nl()->v('string', true, $value, true)->sp(4)->printf('New %s', $prop, 'cyan')->inputv($value);

            $value = trim($value);

            if (ctype_digit($value)) {
                $value = intval($value);
            } else if (is_numeric($value)) {
                $value = floatval($value);
            }

            if ($prop === 'subtitle' && empty($value)) {
                unset($item->{$prop});
            } else {
                $item->{$prop} = $value;
            }

            $this->save();
        } else if ($action === 7) {
            $this->nl(2)->editInvoiceItemPrompts($item);
        }

        if ($action !== 0) {
            $this->nl(2)->editInvoiceItem($client, $index);
        }
    }

    public function editInvoiceItemPrompts($item) {
        // TODO: Make prompts viewable/editable/deletable
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
            $this->clients->push((object) [
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
        $index = $this->list(
            'Client List:',
            'Select a client',
            'Please select a valid client.',
            $this->clients->map->name->all(),
            false,
            'Custom'
        );

        $this->nl(2);

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

                $this->save();
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

    public function command(string $command, ...$values) {
        @exec(sprintf($command, ...$values));

        return $this;
    }

    public function prompt($prompt, string $type = null) {
        $type = $type ?? $prompt->type ?? 'string';

        return $this->input(
            Input::isTypeSupported($type) ? $type : 'string',
            isset($prompt->default),
            $prompt->default ?? null
        )->sp(11)->message($prompt->prompt)->get();
    }

    public function getRenderItems($client, $invoice_number) {
        $total = 0;
        $list = new Collection;
        $items = new Collection;

        $colors = (object) [
            'blue' => new Color(47, 98, 253),
            'gray' => new Color(145, 156, 158),
        ];

        $client_items = collect()->wrap($client->items ?? [])->take(10)->values();

        $this->notice('Invoice Items:');

        foreach ($client_items as $i => $item) {
            $this->listItem($i + 1, '#')->print($item->name, 'cyan')->nl();

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
            $subtitle = trim($subtitle);

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

            $this->v('bool', true, $default, ['No', 'Yes'])->listItem($items->count() + 1, '#')
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
                        'color' => $colors->gray,
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
                    'color' => $colors->blue,
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
                    'color' => $colors->blue,
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
                    'color' => $colors->blue,
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
                    'color' => $colors->blue,
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
                    'color' => $colors->blue,
                ],
            ],

            // Price
            [
                'type' => 'text',
                'position' => ['x' => 450, 'y' => 250],
                'text' => 'Price',
                'font' => [
                    'size' => 9,
                    'color' => $colors->blue,
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
                    'color' => $colors->blue,
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
                    'color' => $colors->blue,
                ],
            ],
            [
                'type' => 'textarea',
                'position' => ['x' => 58, 'y' => 792 - 58 - 21],
                'text' => "Pilot Communications Group\nPO BOX 332, Thompson's Station, TN 37179",
                'font' => [
                    'size' => 9,
                    'line_spacing' => 3,
                    'color' => $colors->blue,
                ],
            ],
        ];
    }
}

new Program;
