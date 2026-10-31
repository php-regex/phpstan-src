<?php

namespace Bug15018;

use function PHPStan\Testing\assertType;

function checkIdentifier(string $id): bool
{
	// preg_match('/^[a-z]+$/s', "abc\n") is 1: $ lets a final newline through, which is lowercase too
	if (1 !== preg_match('/^[a-z]+$/s', $id)) {
		return false;
	}
	assertType('lowercase-string&non-falsy-string', $id);

	return true;
}

function upper(string $s): void
{
	if (preg_match('/^([A-Z]+)-([a-z]+)$/D', $s, $matches)) {
		assertType('non-falsy-string&uppercase-string', $matches[1]);
		assertType('lowercase-string&non-falsy-string', $matches[2]);
	}
}

function caseless(string $s): void
{
	if (preg_match('/^([a-z]+)$/i', $s, $matches)) {
		assertType('non-falsy-string', $matches[1]);
	}
}
