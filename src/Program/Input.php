<?php

namespace Invoices\Program;

use Illuminate\Support\Arr;

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

                    $date = \DateTime::createFromFormat('!' . $input_format, $output);

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
