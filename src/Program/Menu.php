<?php

namespace Invoices\Program;

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
