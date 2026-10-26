<?php declare(strict_types = 1);

namespace Bug14784;

use function PHPStan\Testing\assertType;

function doFoo(string $str): void
{
	if (preg_match('/^(?:0|-?[1-9][0-9]*)$/', $str)) {
		assertType('non-empty-string', $str);
	}

	if (preg_match('/^(0|-?[1-9][0-9]*)$/', $str, $matches)) {
		assertType('non-empty-string', $str);
		assertType("array{non-empty-string, non-empty-string}", $matches);
	}

	if (preg_match('/^(?:0|123)$/', $str)) {
		assertType('\'0\'|"0\\n"|\'123\'|"123\\n"', $str);
	}

	if (preg_match('/^(?:0|[1-9][0-9]*)$/', $str)) {
		assertType('non-empty-string', $str);
	}

	if (preg_match('/^[0-9]+(?:0|5)$/', $str)) {
		assertType('non-falsy-string', $str);
	}

	if (preg_match('/^(?:0|abc)$/', $str)) {
		assertType('\'0\'|"0\\n"|\'abc\'|"abc\\n"', $str);
	}
	if (preg_match('/^(?:2|abc)$/', $str)) {
		assertType('\'2\'|"2\\n"|\'abc\'|"abc\\n"', $str);
	}

	if (preg_match('/^(0|[1-9][0-9]*)$/', $str, $matches)) {
		assertType("numeric-string", $matches[1]);
	}
}
