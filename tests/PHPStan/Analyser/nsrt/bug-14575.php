<?php declare(strict_types = 1);

namespace Bug14575;

use function PHPStan\Testing\assertType;

function doFoo(string $string): void {
	// Anchors in alternation should not make the match non-empty
	if (preg_match('(foo|$)', $string, $match)) {
		assertType('array{\'\'|\'foo\'}', $match);
	}

	if (preg_match('(^|foo)', $string, $match)) {
		assertType('array{\'\'}', $match);
	}

	if (preg_match('(\b|foo)', $string, $match)) {
		assertType('array{\'\'}', $match);
	}

	if (preg_match('/foo|$/', $string, $match)) {
		assertType('array{\'\'|\'foo\'}', $match);
	}

	if (preg_match('/^|bar/', $string, $match)) {
		assertType('array{\'\'}', $match);
	}

	// Anchor in alternation within capturing group
	if (preg_match('/(foo|$)/', $string, $match)) {
		assertType('array{\'\'|\'foo\', \'\'|\'foo\'}', $match);
	}

	if (preg_match('/(^|bar)/', $string, $match)) {
		assertType('array{\'\', \'\'}', $match);
	}

	// Anchor in alternation does not affect parent concatenation
	if (preg_match('/^abc(def|$)/', $string, $match)) {
		assertType("array{'abc'|'abcdef', ''|'def'}", $match);
	}

	// All non-empty alternatives should still produce non-empty/non-falsy
	if (preg_match('/foo|bar/', $string, $match)) {
		assertType('array{\'bar\'|\'foo\'}', $match);
	}

	if (preg_match('/foo/', $string, $match)) {
		assertType('array{\'foo\'}', $match);
	}

	// Anchor alone
	if (preg_match('/^$/', $string, $match)) {
		assertType('array{\'\'}', $match);
	}

	// Three-way alternation with anchor
	if (preg_match('/foo|bar|$/', $string, $match)) {
		assertType('array{\'\'|\'bar\'|\'foo\'}', $match);
	}

	// All alternatives are single chars (non-falsy not determinable from single tokens)
	if (preg_match('/a|b/', $string, $match)) {
		assertType('array{\'a\'|\'b\'}', $match);
	}

	// Empty alternation branches
	if (preg_match('/(|)/', $string, $match)) {
		assertType("array{'', ''}", $match);
	}

	if (preg_match('/(foo|)/', $string, $match)) {
		assertType("array{''|'foo', ''|'foo'}", $match);
	}

	if (preg_match('/(|bar)/', $string, $match)) {
		assertType("array{''|'bar', ''|'bar'}", $match);
	}

	if (preg_match('/|/', $string, $match)) {
		assertType('array{\'\'}', $match);
	}

	if (preg_match('/foo|/', $string, $match)) {
		assertType('array{\'\'|\'foo\'}', $match);
	}
}
