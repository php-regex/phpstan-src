<?php

namespace RegexPatternAnalysedPhp;

function doFoo(string $s): void {
	preg_match('/(a\x{ 41 })/', $s);
	preg_match('/(a\x{41})/', $s);
}
