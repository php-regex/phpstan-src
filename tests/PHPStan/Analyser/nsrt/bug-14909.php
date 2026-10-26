<?php

namespace Bug14909;

use function PHPStan\Testing\assertType;

function decimalInt(string $s): void
{
	// preg_match('/^(0|[1-9]\d{0,17})$/D', '007') is 0: no leading zero, so (string) (int) $v === $v
	if (preg_match('/^(0|[1-9]\d{0,17})$/D', $s, $matches)) {
		assertType('decimal-int-string', $matches[1]);
		assertType('int', $matches[1] + 1);
	}
	// \d+ reads '007'
	if (preg_match('/^(\d+)$/', $s, $matches)) {
		assertType('numeric-string', $matches[1]);
	}
}
