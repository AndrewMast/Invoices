<?php

require __DIR__ . '/vendor/autoload.php';

use App\Pdf\Data\Color;
use App\Pdf\Generator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Wujunze\Colors;

class Program {
    public function __construct() {
        $this->input = null;
        $this->output = new Output($this);

        $this->load();

        $menu = new Menu($this, 'Select Action', 'Select action', 'Please select a valid action');

        $menu->showing(function(Menu $menu) {
            $menu->items(
                new MenuItem([['Edit settings', 'brown']], $this->createEditSettingsMenu($menu)),
                new MenuItem([['Create new client', 'green']], $this->createNewClientMenu($menu))
            );

            foreach ($this->clients as $client) {
                $menu->item(new MenuItem($client->name, $this->createClientMenu($menu, $client)));
            }
        });

        $menu->show();
    }

    public function createEditSettingsMenu(Menu $parent) {
        $menu = new Menu($parent, 'Editing Settings', 'Select action', 'Please select a valid action');

        $copy = clone $this->settings;

        $menu->exiting(function(Menu $menu) use ($copy) {
            $this->settings->{'billing-address'} = $copy->{'billing-address'};
            $this->settings->{'next-invoice-number'} = $copy->{'next-invoice-number'};
            $this->settings->{'storage-path'} = $copy->{'storage-path'};

            $this->save();
        });

        $menu->showing(function(Menu $menu) {
            $menu->items(
                new MenuItem(
                    [['Save & Edit', 'green']],
                    $this->createSaveAndExitMenu($menu)
                ),
                new MenuItem(
                    ['Edit ', ['Billing Address', 'purple'], ' (', [$this->escapeAddress($this->settings->{'billing-address'}), 'brown'], ')'],
                    $this->createEditAttributeMenu($menu, 'Billing Address', $this->settings, 'billing-address')
                ),
                new MenuItem(
                    ['Edit ', ['Next Invoice Number', 'purple'], ' (', [$this->settings->{'next-invoice-number'}, 'brown'], ')'],
                    $this->createEditAttributeMenu($menu, 'Next Invoice Number', $this->settings, 'next-invoice-number', 'int')
                ),
                new MenuItem(
                    ['Edit ', ['Storage Path', 'purple'], ' (', [$this->settings->{'storage-path'}, 'brown'], ')'],
                    $this->createEditAttributeMenu($menu, ['Storage Path (', ['optional', 'gray'], ')'], $this->settings, 'storage-path', 'path:folder', true, output_path())
                )
            );
        });

        return $menu;
    }

    public function createClientMenu(Menu $parent, $client) {
        $menu = new Menu($parent, null, 'Select action', 'Please select a valid action');

        $menu->showing(function(Menu $menu) use ($client) {
            $menu->title = ['Actions for ', [$client->name, 'green']];

            $next = $this->settings->{'next-invoice-number'};

            $menu->items(
                new MenuItem(['Create Next Invoice (', [$next, 'brown'], ')'], $this->createInvoiceMenu($menu, $client, $next)),
                new MenuItem(['Create Custom Invoice'], $this->createInvoiceMenu($menu, $client)),
                new MenuItem(['Edit Client'], $this->createEditClientMenu($menu, $client))
            );
        });

        $menu->default(1);

        return $menu;
    }

    public function createInvoiceMenu(Menu $menu, $client, $invoice_number = null) {
        return function() use ($menu, $client, $invoice_number) {
            $this->clear()->print('Creating Invoice for Client:', 'brown')->nl()
                 ->sp(4)->print('Name', 'cyan')->arrow()->print($client->name)->nl()
                 ->sp(4)->print('Id', 'cyan')->arrow()->print($client->id)->nl()
                 ->sp(4)->print('Billed To', 'cyan')->arrow()->print($client->{'billed-to'})->nl();

            if ($invoice_number === null) {
                $this->input('int')->sp(4)->message('Invoice #')->set($invoice_number);
            } else {
                $this->sp(4)->print('Invoice #', 'cyan')->arrow()->print($invoice_number)->nl();
            }

            $searches = [];
            $replacements = [];

            if (count($client->prompts) > 0) {
                $this->nl();

                foreach ($client->prompts as $prompt) {
                    $help = [];

                    $type = str_replace($searches, $replacements, (string) $prompt->type);
                    $message = str_replace($searches, $replacements, (string) $prompt->message);
                    $hint = $prompt->hint === null ? null : str_replace($searches, $replacements, (string) $prompt->hint);
                    $nullable = boolval(str_replace($searches, $replacements, (string) $prompt->nullable));
                    $default = $prompt->default === null ? null : str_replace($searches, $replacements, (string) $prompt->default);
                    $ask = boolval(str_replace($searches, $replacements, (string) $prompt->ask));

                    if ($hint !== null) {
                        $help = [' (', [$hint, 'purple'], ')'];
                    } else if ($nullable === true) {
                        $help = [' (', $default === null ? ['optional', 'gray'] : [$default, 'brown'], ')'];
                    }

                    if ($ask) {
                        $searches[] = sprintf('<<%s>>', $prompt->id);
                        $replacements[] = $this->input($type, $nullable, $default, true)->sp(4)->message($message)->message($help)->get();
                    }
                }
            }

            $items = collect($client->items)->sortBy('name')->values()->take(10);

            $render_items = collect();
            $total = 0;

            $count = 0;

            foreach ($items as $item) {
                $title = str_replace($searches, $replacements, (string) $item->title);
                $subtitle = trim(str_replace($searches, $replacements, (string) $item->subtitle));
                $price = intval(str_replace($searches, $replacements, (string) $item->price));
                $quantity = intval(str_replace($searches, $replacements, (string) $item->quantity));

                if ($quantity <= 0) {
                    continue;
                }

                $count++;

                $this->nl()->printl('#' . $count, 4, 'light_green');
                $this->sep()->print('Title', 'cyan')->arrow()->print($title, 'brown')->nl();

                if (!empty($subtitle)) {
                    $this->sp(7)->print('Subtitle', 'cyan')->arrow()->print($subtitle, 'brown')->nl();
                }

                $this->sp(7)->print('Price', 'cyan')->arrow()->print($this->money($price), 'brown')->nl();
                $this->sp(7)->print('Quantity', 'cyan')->arrow()->print($quantity, 'brown')->nl();

                $render_items->push([
                    'title' => $title,
                    'subtitle' => empty($subtitle) ? null : $subtitle,
                    'price' => $price,
                    'quantity' => $quantity,
                ]);

                $total += $price * $quantity;
            }

            while ($render_items->count() < 10) {
                $this->nl()->input('bool', true, $render_items->count() === 0, ['No', 'Yes'])->sp(7)->message([
                    'Add an item to the invoice? [',
                    ...($render_items->count() === 0 ? [['Y', 'brown'], '/', ['n', 'brown']] : [['y', 'brown'], '/', ['N', 'brown']]),
                    ']'
                ])->set($add);

                if (!$add) {
                    break;
                }

                $this->nl()->sp(7)->print('Adding New Item:', 'brown')->nl()
                     ->input()->sp(11)->message('Title')->set($title)
                     ->input('string', true, null, true)->sp(11)->message(['Subtitle (', ['optional', 'gray'], ')'])->set($subtitle)
                     ->input()->sp(11)->message('Price')->set($price)
                     ->input('string', true, 1, true)->sp(11)->message(['Quantity (', ['1', 'brown'], ')'])->set($quantity);

                $title = str_replace($searches, $replacements, (string) $title);
                $subtitle = trim(str_replace($searches, $replacements, (string) $subtitle));
                $price = intval(str_replace($searches, $replacements, (string) $price));
                $quantity = intval(str_replace($searches, $replacements, (string) $quantity));

                if ($quantity <= 0) {
                    continue;
                }

                $count++;

                $this->nl()->printl('#' . $count, 4, 'light_green');
                $this->sep()->print('Title', 'cyan')->arrow()->print($title, 'brown')->nl();

                if (!empty($subtitle)) {
                    $this->sp(7)->print('Subtitle', 'cyan')->arrow()->print($subtitle, 'brown')->nl();
                }

                $this->sp(7)->print('Price', 'cyan')->arrow()->print($this->money($price), 'brown')->nl();
                $this->sp(7)->print('Quantity', 'cyan')->arrow()->print($quantity, 'brown')->nl();

                $render_items->push([
                    'title' => $title,
                    'subtitle' => empty($subtitle) ? null : $subtitle,
                    'price' => $price,
                    'quantity' => $quantity,
                ]);

                $total += $price * $quantity;
            }

            $this->nl()->input('bool', true, true, ['No', 'Yes'])
                 ->message(['Are you sure you want to create this invoice? [', ['Y', 'brown'], '/', ['n', 'brown'], ']'])
                 ->set($confirm)->nl();

            if ($confirm) {
                $generator = new Generator;

                $pdf = $generator->render($this->render($render_items, $client, $invoice_number, $total));

                $filepath = $this->saveFile(
                    sprintf('invoice%s-%s.pdf', $this->leading($invoice_number, '0', 6), $client->id),
                    $pdf->contents()
                );

                if ($invoice_number === $this->settings->{'next-invoice-number'}) {
                    $this->settings->{'next-invoice-number'}++;
                }

                $this->save();

                $this->printf('Successfully created invoice #%d for %s!', [$invoice_number, $client->name], 'green')->nl(2)
                     ->input('bool', true, false, ['No', 'Yes'])->message(['Do you want to open the invoice? [', ['y', 'brown'], '/', ['N', 'brown'], ']'])
                     ->set($open);

                if (php_uname('s') === 'Darwin') {
                    $this->command($open ? 'open "%s"' : 'open -R "%s"', $filepath);
                } else {
                    $this->command($open ? 'start "" "%s"' : 'explorer.exe /select, "%s"', $filepath);
                }
            } else {
                $this->printf('Canceled the creation of invoice #%d for %s.', [$invoice_number, $client->name], 'red')->nl();

                sleep(2);

                $menu->show();
            }
        };
    }

    public function createEditClientMenu(Menu $parent, $client) {
        $menu = new Menu($parent, null, 'Select action', 'Please select a valid action');

        $copy = clone $client;

        $menu->exiting(function(Menu $menu) use ($client, $copy) {
            $client->id = $copy->id;
            $client->name = $copy->name;
            $client->{'billed-to'} = $copy->{'billed-to'};

            $this->save();
        });

        $menu->showing(function(Menu $menu) use ($client) {
            $menu->title = ['Editing ', [$client->name, 'green']];

            $menu->items(
                new MenuItem(
                    [['Save & Edit', 'green']],
                    $this->createSaveAndExitMenu($menu)
                ),
                new MenuItem(
                    ['Edit ', ['Name', 'purple'], ' (', [$client->name, 'brown'], ')'],
                    $this->createEditAttributeMenu($menu, 'Name', $client, 'name')
                ),
                new MenuItem(
                    ['Edit ', ['Id', 'purple'], ' (', [$client->id, 'brown'], ')'],
                    $this->createEditAttributeMenu($menu, 'Id', $client, 'id', 'string:lower')
                ),
                new MenuItem(
                    ['Edit ', ['Billed To', 'purple'], ' (', [$client->{'billed-to'}, 'brown'], ')'],
                    $this->createEditAttributeMenu($menu, 'Billed To', $client, 'billed-to')
                ),
                new MenuItem(
                    'Edit Invoice Items',
                    $this->createEditInvoiceItemsMenu($menu, $client)
                ),
                new MenuItem(
                    'Edit Prompts',
                    $this->createEditPromptsMenu($menu, $client)
                ),
                new MenuItem([['Delete Client', 'light_red']], $this->createRemoveClientMenu($menu, $client))
            );
        });

        return $menu;
    }

    public function createNewClientMenu(Menu $menu) {
        return function() use ($menu) {
            $this->clear()->print('Creating Client:', 'brown')->nl()
                 ->input()->sp(4)->message('Name')->set($name)
                 ->input('string:lower')->sp(4)->message('Id')->set($id)
                 ->input()->sp(4)->message('Billed To')->set($billed)
                 ->nl()->input('bool', true, true, ['No', 'Yes'])
                 ->message(['Are you sure you want to create this client? [', ['Y', 'brown'], '/', ['n', 'brown'], ']'])
                 ->set($confirm)->nl();

            if ($confirm) {
                $client = (object) [
                    'name' => $name,
                    'id' => $id,
                    'billed-to' => $billed,
                    'items' => [],
                    'prompts' => [],
                ];

                $this->clients->push($client);

                $this->save();

                $this->printf('Successfully created the client "%s"!', $name, 'green')->nl();

                sleep(2);

                $this->createClientMenu($menu, $client)->show();
            } else {
                $this->printf('Canceled the creation of the client "%s".', $name, 'red')->nl();

                sleep(2);

                $menu->show();
            }
        };
    }

    public function createRemoveClientMenu(Menu $menu, $client) {
        return function() use ($menu, $client) {
            $this->nl()->input('bool', true, false, ['No', 'Yes'])
                 ->message(['Are you sure you want to delete this client? [', ['y', 'brown'], '/', ['N', 'brown'], ']'])
                 ->set($confirm)->nl();

            if ($confirm) {
                $index = $this->clients->search($client);

                if ($index === false) {
                    throw new \Exception('Cannot find client to delete!');
                }

                $this->clients->splice($index, 1);

                $this->save();

                $this->printf('Deleted the client "%s".', $client->name, 'red')->nl();

                sleep(2);

                $menu->parent->exit();
            } else {
                $this->printf('Canceled the deletion of the client "%s".', $client->name, 'green')->nl();

                sleep(2);

                $menu->show();
            }
        };
    }

    public function createEditInvoiceItemsMenu(Menu $parent, $client) {
        $menu = new Menu($parent, ['Editing Invoice Items for ', [$client->name, 'green']], 'Select item', 'Please select a valid invoice item');

        $menu->showing(function(Menu $menu) use ($client) {
            $items = $client->items;

            $menu->items(new MenuItem([['Create new invoice item', 'green']], $this->createNewInvoiceItemMenu($menu, $client)));

            foreach ($items as $i => $item) {
                $menu->item(new MenuItem(
                    ['Edit ', [$item->name, $i >= 10 ? 'light_red' : 'purple']],
                    $this->createEditInvoiceItemMenu($menu, $client, $item)
                ));
            }
        });

        return $menu;
    }

    public function createEditInvoiceItemMenu(Menu $parent, $client, $item) {
        $menu = new Menu($parent, null, 'Select action', 'Please select a valid action');

        $copy = clone $item;

        $menu->exiting(function(Menu $menu) use ($item, $copy) {
            $item->name = $copy->name;
            $item->title = $copy->title;
            $item->subtitle = $copy->subtitle;
            $item->price = $copy->price;
            $item->quantity = $copy->quantity;

            $this->save();
        });

        $menu->showing(function(Menu $menu) use ($client, $item) {
            $menu->title = ['Editing Invoice Item ', [$item->name, 'green']];

            $menu->items(
                new MenuItem(
                    [['Save & Edit', 'green']],
                    $this->createSaveAndExitMenu($menu)
                ),
                new MenuItem(
                    ['Edit ', ['Name', 'purple'], ' (', [$item->name, 'brown'], ')'],
                    $this->createEditAttributeMenu($menu, 'Name', $item, 'name')
                ),
                new MenuItem(
                    ['Edit ', ['Title', 'purple'], ' (', [$item->title, 'brown'], ')'],
                    $this->createEditAttributeMenu($menu, 'Title', $item, 'title')
                ),
                new MenuItem(
                    ['Edit ', ['Subtitle', 'purple'], ' (', empty($item->subtitle) ? ['null', 'gray'] : [$item->subtitle, 'brown'], ')'],
                    $this->createEditAttributeMenu($menu, ['Subtitle (', ['optional', 'gray'], ')'], $item, 'subtitle', 'string', true, null, true)
                ),
                new MenuItem(
                    ['Edit ', ['Price', 'purple'], ' (', [$item->price, 'brown'], ')'],
                    $this->createEditAttributeMenu($menu, 'Price', $item, 'price')
                ),
                new MenuItem(
                    ['Edit ', ['Quantity', 'purple'], ' (', [$item->quantity, 'brown'], ')'],
                    $this->createEditAttributeMenu($menu, ['Quantity (', ['1', 'brown'], ')'], $item, 'quantity', 'string', true, 1, true)
                ),
                new MenuItem([['Delete Invoice Item', 'light_red']], $this->createRemoveInvoiceItemMenu($menu, $client, $item))
            );
        });

        return $menu;
    }

    public function createNewInvoiceItemMenu(Menu $menu, $client) {
        return function() use ($menu, $client) {
            $this->clear()->print(['Creating Invoice Item for ', [$client->name, 'green'], ':'], 'brown')->nl()
                 ->input()->sp(4)->message('Name')->set($name)
                 ->input()->sp(4)->message('Title')->set($title)
                 ->input('string', true, null, true)->sp(4)->message(['Subtitle (', ['optional', 'gray'], ')'])->set($subtitle)
                 ->input()->sp(4)->message('Price')->set($price)
                 ->input('string', true, 1, true)->sp(4)->message(['Quantity (', ['1', 'brown'], ')'])->set($quantity)
                 ->nl()->input('bool', true, true, ['No', 'Yes'])
                 ->message(['Are you sure you want to create this invoice item? [', ['Y', 'brown'], '/', ['n', 'brown'], ']'])
                 ->set($confirm)->nl();

            if ($confirm) {
                array_push($client->items, (object) [
                    'name' => $name,
                    'title' => $title,
                    'subtitle' => $subtitle,
                    'price' => $price,
                    'quantity' => $quantity,
                ]);

                $this->save();

                $this->printf('Successfully created the invoice item "%s"!', $name, 'green')->nl();
            } else {
                $this->printf('Canceled the creation of the invoice item "%s".', $name, 'red')->nl();
            }

            sleep(2);

            $menu->show();
        };
    }

    public function createRemoveInvoiceItemMenu(Menu $menu, $client, $item) {
        return function() use ($menu, $client, $item) {
            $this->nl()->input('bool', true, false, ['No', 'Yes'])
                 ->message(['Are you sure you want to delete this invoice item? [', ['y', 'brown'], '/', ['N', 'brown'], ']'])
                 ->set($confirm)->nl();

            if ($confirm) {
                $index = array_search($item, $client->items);

                if ($index === false) {
                    throw new \Exception('Cannot find invoice item to delete!');
                }

                array_splice($client->items, $index, 1);

                $this->save();

                $this->printf('Deleted the invoice item "%s".', $item->name, 'red')->nl();

                sleep(2);

                $menu->exit();
            } else {
                $this->printf('Canceled the deletion of the invoice item "%s".', $item->name, 'green')->nl();

                sleep(2);

                $menu->show();
            }
        };
    }

    public function createEditPromptsMenu(Menu $parent, $client) {
        $menu = new Menu($parent, ['Editing Prompts for ', [$client->name, 'green']], 'Select prompt', 'Please select a valid prompt');

        $menu->showing(function(Menu $menu) use ($client) {
            $prompts = $client->prompts;

            $menu->items(new MenuItem([['Create new prompt', 'green']], $this->createNewPromptMenu($menu, $client)));

            foreach ($prompts as $prompt) {
                $menu->item(new MenuItem(
                    ['Edit ', [$prompt->name, 'purple']],
                    $this->createEditPromptMenu($menu, $client, $prompt)
                ));
            }
        });

        return $menu;
    }

    public function createEditPromptMenu(Menu $parent, $client, $prompt) {
        $menu = new Menu($parent, null, 'Select action', 'Please select a valid action');

        $copy = clone $prompt;

        $menu->exiting(function(Menu $menu) use ($prompt, $copy) {
            $prompt->name = $copy->name;
            $prompt->id = $copy->id;
            $prompt->type = $copy->type;
            $prompt->message = $copy->message;
            $prompt->hint = $copy->hint;
            $prompt->nullable = $copy->nullable;
            $prompt->default = $copy->default;

            $this->save();
        });

        $menu->showing(function(Menu $menu) use ($client, $prompt) {
            $menu->title = ['Editing Prompt ', [$prompt->name, 'green']];

            $menu->items(
                new MenuItem(
                    [['Save & Edit', 'green']],
                    $this->createSaveAndExitMenu($menu)
                ),
                new MenuItem(
                    ['Edit ', ['Name', 'purple'], ' (', [$prompt->name, 'brown'], ')'],
                    $this->createEditAttributeMenu($menu, 'Name', $prompt, 'name')
                ),
                new MenuItem(
                    ['Edit ', ['Id', 'purple'], ' (', [$prompt->id, 'brown'], ')'],
                    $this->createEditAttributeMenu($menu, 'Id', $prompt, 'id', 'string:lower')
                ),
                new MenuItem(
                    ['Edit ', ['Type', 'purple'], ' (', [$prompt->type, 'brown'], ')'],
                    $this->createEditAttributeMenu($menu, 'Type', $prompt, 'type')
                ),
                new MenuItem(
                    ['Edit ', ['Message', 'purple'], ' (', [$prompt->message, 'brown'], ')'],
                    $this->createEditAttributeMenu($menu, 'Message', $prompt, 'message')
                ),
                new MenuItem(
                    ['Edit ', ['Hint', 'purple'], ' (', empty($prompt->hint) ? ['null', 'gray'] : [$prompt->hint, 'brown'], ')'],
                    $this->createEditAttributeMenu($menu, ['Hint (', ['optional', 'gray'], ')'], $prompt, 'hint', 'string', true, null, true)
                ),
                new MenuItem(
                    ['Edit ', ['Nullable', 'purple'], ' (', [$prompt->nullable ? 'True' : 'False', 'brown'], ')'],
                    $this->createEditAttributeMenu($menu, ['Nullable (', ['False', 'brown'], ')'], $prompt, 'nullable', 'string', true, null, true)
                ),
                new MenuItem(
                    ['Edit ', ['Default', 'purple'], ' (', empty($prompt->default) ? ['null', 'gray'] : [$prompt->default, 'brown'], ')'],
                    $this->createEditAttributeMenu($menu, ['Default (', ['optional', 'gray'], ')'], $prompt, 'default', 'string', true, null, true)
                ),
                new MenuItem([['Delete Prompt', 'light_red']], $this->createRemovePromptMenu($menu, $client, $prompt))
            );
        });

        return $menu;
    }

    public function createNewPromptMenu(Menu $menu, $client) {
        return function() use ($menu, $client) {
            $this->clear()->print(['Creating Prompt for ', [$client->name, 'green'], ':'], 'brown')->nl()
                 ->input()->sp(4)->message('Name')->set($name)
                 ->input('string:lower')->sp(4)->message('Id')->set($id)
                 ->input()->sp(4)->message('Type')->set($type)
                 ->input()->sp(4)->message('Message')->set($message)
                 ->input('string', true, null, true)->sp(4)->message(['Hint (', ['optional', 'gray'], ')'])->set($hint)
                 ->input('bool', true, false, ['False', 'True'])->sp(4)->message(['Nullable (', ['False', 'brown'], ')'])->set($nullable)
                 ->input('string', true, null, true)->sp(4)->message(['Default (', ['optional', 'gray'], ')'])->set($default)
                 ->nl()->input('bool', true, true, ['No', 'Yes'])
                 ->message(['Are you sure you want to create this prompt? [', ['Y', 'brown'], '/', ['n', 'brown'], ']'])
                 ->set($confirm)->nl();

            if ($confirm) {
                array_push($client->prompts, (object) [
                    'name' => $name,
                    'id' => $id,
                    'type' => $type,
                    'message' => $message,
                    'hint' => $hint,
                    'nullable' => $nullable,
                    'default' => $default,
                ]);

                $this->save();

                $this->printf('Successfully created the prompt "%s"!', $name, 'green')->nl();
            } else {
                $this->printf('Canceled the creation of the prompt "%s".', $name, 'red')->nl();
            }

            sleep(2);

            $menu->show();
        };
    }

    public function createRemovePromptMenu(Menu $menu, $client, $prompt) {
        return function() use ($menu, $client, $prompt) {
            $this->nl()->input('bool', true, false, ['No', 'Yes'])
                 ->message(['Are you sure you want to delete this prompt? [', ['y', 'brown'], '/', ['N', 'brown'], ']'])
                 ->set($confirm)->nl();

            if ($confirm) {
                $index = array_search($prompt, $client->prompts);

                if ($index === false) {
                    throw new \Exception('Cannot find prompt to delete!');
                }

                array_splice($client->prompts, $index, 1);

                $this->save();

                $this->printf('Deleted the prompt "%s".', $prompt->name, 'red')->nl();

                sleep(2);

                $menu->exit();
            } else {
                $this->printf('Canceled the deletion of the prompt "%s".', $prompt->name, 'green')->nl();

                sleep(2);

                $menu->show();
            }
        };
    }

    public function createEditAttributeMenu(Menu $menu, $title, $object, $attribute, ...$validator) {
        return function() use ($menu, $title, $object, $attribute, $validator) {
            $this->nl()->print('Enter new value', 'brown')->nl()
                 ->input(...$validator)->sp(4)->message($title)->set($object->{$attribute});

            $menu->show();
        };
    }

    public function createSaveAndExitMenu(Menu $menu) {
        return function() use ($menu) {
            $this->save();

            $menu->exit();
        };
    }

    public function fillSettings() {
        if (!file_exists(output_path())) {
            mkdir(output_path(), 0777, true);
        }

        $this->clear()->print('Enter Settings', 'brown')->nl()
             ->input()->sp(4)->message('Billing Address')->set($address)
             ->input('int')->sp(4)->message('Starting Invoice #')->set($invoice)
             ->input('path:folder', true, output_path())->sp(4)->message(['Storage Path (', ['optional', 'gray'], ')'])->set($path);

        file_put_contents($this->settings_file, json_encode([
            'billing-address' => $this->unescapeAddress($address),
            'next-invoice-number' => $invoice,
            'storage-path' => $path,
        ], JSON_PRETTY_PRINT) . PHP_EOL);
    }

    public function unescapeAddress(string $address) {
        return str_replace(["\\t", "\\n"], ["\t", "\n"], $address);
    }

    public function escapeAddress(string $address) {
        return str_replace(["\t", "\n"], ["\\t", "\\n"], $address);
    }

    public function load() {
        if (!file_exists(store_path())) {
            mkdir(store_path(), 0777, true);
        }

        $this->clients_file = store_path('clients.json');

        $this->clients = new Collection(file_exists($this->clients_file) ? json_decode(file_get_contents($this->clients_file)) : []);

        $this->settings_file = store_path('settings.json');

        if (!file_exists($this->settings_file)) {
            $this->fillSettings();
        }

        $this->settings = json_decode(file_get_contents($this->settings_file));

        return $this;
    }

    public function save() {
        $this->clients = $this->clients->sortBy('name');

        foreach ($this->clients as $client) {
            $client->items = collect($client->items)->sortBy('name')->values()->all();
            $client->prompts = collect($client->prompts)->sortBy('name')->values()->all();
        }

        file_put_contents($this->clients_file, json_encode($this->clients->all(), JSON_PRETTY_PRINT) . PHP_EOL);

        file_put_contents($this->settings_file, json_encode($this->settings, JSON_PRETTY_PRINT) . PHP_EOL);

        return $this;
    }

    public function saveFile($file, $contents) {
        $path = rtrim($this->settings->{'storage-path'}, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file;

        if (!file_exists($this->settings->{'storage-path'})) {
            mkdir($this->settings->{'storage-path'}, 0777, true);
        }

        file_put_contents($path, $contents);

        return $path;
    }

    public function exit() {
        $this->print('Exiting...', 'red')->nl();

        die;
    }

    public function input($validator = 'string', $nullable = false, $default = null, $printback = false) {
        return $this->input = new Input($this, $validator, $nullable, $default, $printback);
    }

    public function command(string $command, ...$values) {
        @exec(sprintf($command, ...$values));

        return $this;
    }

    public function render(Collection $items, $client, $invoice_number, $total) {
        $height = 270;
        $list = new Collection;

        $colors = (object) [
            'blue' => new Color(47, 98, 253),
            'gray' => new Color(145, 156, 158),
        ];

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
            /* Background & Header */
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


            /* Billed To */
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

            /* Invoice Number */
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

            /* Date of Issue */
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

            /* Invoice Total */
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


            /* Item */
            [
                'type' => 'text',
                'position' => ['x' => 58, 'y' => 250],
                'text' => 'Item',
                'font' => [
                    'size' => 9,
                    'color' => $colors->blue,
                ],
            ],

            /* Price */
            [
                'type' => 'text',
                'position' => ['x' => 450, 'y' => 250],
                'text' => 'Price',
                'font' => [
                    'size' => 9,
                    'color' => $colors->blue,
                ],
            ],

            /* Qty */
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


            /* Thanks & Footer */
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
                'text' => $this->settings->{'billing-address'},
                'font' => [
                    'size' => 9,
                    'line_spacing' => 3,
                    'color' => $colors->blue,
                ],
            ],
        ];
    }

    public function __call($name, $arguments) {
        if (method_exists($this->output, $name)) {
            $output = $this->output->{$name}(...$arguments);

            return $output === $this->output ? $this : $output;
        }

        throw new \Exception("Unknown method '$name'");
    }
}

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
        $this->on_exiting = null;
    }

    public function items(...$items) {
        $this->items = [];

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

    public function exiting($on_exiting = null) {
        $this->on_exiting = $on_exiting;

        return $this;
    }

    protected function callback(string $callback) {
        if ($this->{'on_' . $callback} ?? false && is_callable($this->{'on_' . $callback})) {
            call_user_func($this->{'on_' . $callback}, $this);
        }
    }

    public function show() {
        $this->callback('showing');

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
             ->set($index);

        if ($index === 0) {
            $this->callback('exiting');

            $this->exit();
        } else {
            $this->items[$index - 1]->call();
        }
    }

    public function exit() {
        if ($this->parent === null) {
            $this->program->exit();
        } else {
            $this->parent->show();
        }
    }

    public function __call($name, $arguments) {
        if ($name === 'input') {
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
            die; /* Ctrl+C */
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
                if (!static::isTypeSupported($validator)) {
                    $validator = 'string';
                }

                $null = false;

                if ($this->nullable && $output === '') {
                    $output = $this->default;

                    $null = true;
                }

                if ($validator === 'string' || $validator === 'string:lower' || $validator === 'string:upper') {
                    if ($output === '' && $null === false) {
                        $this->error('Please enter a value.');

                        return $this->get();
                    } else if ($validator === 'string:lower' && !ctype_lower($output)) {
                        $this->error('Please enter a string using only lowercase letters.');

                        return $this->get();
                    } else if ($validator === 'string:upper' && !ctype_upper($output)) {
                        $this->error('Please enter a string using only uppercase letters.');

                        return $this->get();
                    }
                }

                if ($validator === 'path' || $validator === 'path:file' || $validator === 'path:folder') {
                    $path = realpath($output);

                    if ($path === false || !file_exists($path)) {
                        $this->error('This path does not exist!');

                        return $this->get();
                    } else if ($validator === 'path:file' && !is_file($path)) {
                        $this->error('This path does not lead to a file!');

                        return $this->get();
                    } else if ($validator === 'path:folder' && !is_dir($path)) {
                        $this->error('This path does not lead to a folder!');

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

                    $date = DateTime::createFromFormat('!' . $input_format, $output);

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
            } else if (in_array('bool', $validators) && is_bool($output)) {
                $print = ['False', 'True'][$output];
            }

            $this->printBack(1, $this->getWhitespaceCount() + 4, $print);
        }

        $this->program->input = null;

        return $output;
    }

    public function getWhitespaceCount() {
        $lines = explode(PHP_EOL, $this->strip($this->message));

        return strlen(trim(end($lines), "\r\n"));
    }

    public static function isTypeSupported(string $type) {
        return in_array(strtolower($type), ['string', 'string:lower', 'string:upper', 'int', 'bool', 'path', 'path:file', 'path:folder'])
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

    public function moveBack($rows, $cols) {
        return $this->printf("\033[%dA\033[%dC", [$rows, $cols]);
    }

    public function clearLine() {
        return $this->print("\033[K");
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

    public function leading($value, $filler, $count) {
        if (str_contains($value, "\033")) {
            $strip = $this->strip($value);

            return str_replace($strip, str_repeat($filler, max(0, $count - strlen($strip))) . $strip, $value);
        }

        return sprintf("%'.{$filler}{$count}s", $value);
    }

    public function money($value) {
        if ($value >= 0) {
            return sprintf('$%d', $value);
        } else {
            return sprintf('($%d)', abs($value));
        }
    }
}

new Program;
