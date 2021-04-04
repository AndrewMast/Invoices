<?php

namespace Invoices\Pdf\Data;

class Color {
    public $r;
    public $g;
    public $b;

    public function __construct(int $r, int $g, int $b) {
        $this->r = $r;
        $this->g = $g;
        $this->b = $b;
    }

    public function array() {
        return [
            $this->r,
            $this->g,
            $this->b,
        ];
    }
}
