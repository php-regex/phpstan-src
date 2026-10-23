<?php declare(strict_types = 1);

namespace Bug14809;

use function PHPStan\Testing\assertType;

function doFoo(string $s): void
{
	// the outer "(" / ")" are the regex delimiters, the actual pattern is ".?(.|$)"
	// the "(.|$)" branch may match the empty string at "$", so the whole match may be empty
	if (preg_match('(.?(.|$))', $s, $m)) {
		assertType('array{string, string}', $m);
	}

	if (preg_match('((.?(.|$)))', $s, $m)) {
		assertType('array{string, string, string}', $m);
	}

	if (preg_match('((.?(.|.?)))', $s, $m)) {
		assertType('array{string, string, string}', $m);
	}

	// guard: an alternation whose branches are all non-empty stays non-falsy
	if (preg_match('~(a(b|c))~', $s, $m)) {
		assertType("array{'ab'|'ac', 'ab'|'ac', 'b'|'c'}", $m);
	}

	if (preg_match('~((a|b)(b|c))~', $s, $m)) {
		assertType("array{'ab'|'ac'|'bb'|'bc', 'ab'|'ac'|'bb'|'bc', 'a'|'b', 'b'|'c'}", $m);
	}

	if (preg_match('~((b|c)d)~', $s, $m)) {
		assertType("array{'bd'|'cd', 'bd'|'cd', 'b'|'c'}", $m);
	}

	if (preg_match('~(a(b|c)d)~', $s, $m)) {
		assertType("array{'abd'|'acd', 'abd'|'acd', 'b'|'c'}", $m);
	}
}
