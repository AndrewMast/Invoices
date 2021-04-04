<?php

namespace Invoices\Program;

use Invoices\Pdf\Data\Color;
use Invoices\Pdf\Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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

        $menu->exiting(function() use ($copy) {
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

        $menu->exiting(function() use ($client, $copy) {
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

        $menu->exiting(function() use ($item, $copy) {
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

        $menu->exiting(function() use ($prompt, $copy) {
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
