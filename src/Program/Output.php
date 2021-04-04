<?php

namespace Invoices\Program;

use Illuminate\Support\Arr;
use Wujunze\Colors;

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
