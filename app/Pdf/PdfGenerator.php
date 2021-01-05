<?php

namespace App\Pdf;

use Illuminate\Support\Str;

class PdfGenerator {
	public $fpdf;

	public function __construct(Fpdf $fpdf = null, $orientation = null, $unit = null, $size = null) {
		$this->fpdf = $fpdf ?? new Fpdf($orientation ?? 'P', $unit ?? 'mm', $size ?? 'A4');
	}

	public function __clone() {
		$this->fpdf = clone $this->fpdf;
	}

	public static function create(...$arguments) {
		return new static(...$arguments);
	}

	public function __call($method, $arguments) {
		$method = Str::camel($method);

		if (method_exists($this, $method)) {
			return $this->{$method}(...$arguments);
		} else if (method_exists($this->fpdf, $method)) {
			return $this->fpdf->{$method}(...$arguments);
		}

		return $this;
	}

	function setMetadata($metadata, $value) {
        $this->metadata[$metadata] = $value;
    }

	public function contents() {
		return (clone $this->fpdf)->Output('S');
	}
}
