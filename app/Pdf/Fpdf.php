<?php

namespace App\Pdf;

use Crabbly\Fpdf\Fpdf as CrabblyFpdf;

class Fpdf extends CrabblyFpdf {
	public function __construct($orientation='P', $unit='mm', $size='a4') {
		parent::__construct($orientation, $unit, $size);

		$this->fontpath = font_path();

		$margin = 0;

		$this->SetMargins($margin, $margin);
		$this->SetAutoPageBreak(true, $margin);
	}

	protected function _putinfo() {
		if (!isset($this->metadata) || !is_array($this->metadata)) {
			return;
		}

		foreach($this->metadata as $key=>$value) {
			$this->_put('/'.$key.' '.$this->_textstring($value));
		}
	}

	protected function _getpagesize($size) {
		$sizes = [
			'4a0' => [4768, 6741],
			'2a0' => [3370, 4768],
			'a0'  => [2384, 3370],
			'a1'  => [1684, 2384],
			'a2'  => [1191, 1684],
			'a3'  => [842, 1191],
			'a4'  => [595, 842],
			'a5'  => [420, 595],
			'a6'  => [298, 420],
			'a7'  => [210, 298],
			'a8'  => [147, 210],
			'a9'  => [105, 147],
			'a10' => [74, 105],
			'letter' => [612,792],
			'legal'  => [612,1008]
		];

		if (is_string($size)) {
			$size = strtolower($size);
			if(!isset($sizes[$size]))
				$this->Error('Unknown page size: '.$size);
			$a = $sizes[$size];
			return [$a[0]/$this->k, $a[1]/$this->k];
		} else {
			if ($size[0]>$size[1]) {
				return [$size[1], $size[0]];
			} else {
				return $size;
			}
		}
	}
}
