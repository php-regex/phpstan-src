<?php // lint >= 7.4

namespace Bug12242;

use function PHPStan\Testing\assertType;

function foo(string $str): void
{
	$regexp = '/
		# (
		([\d,]*)
		# )
	/x';
	if (preg_match($regexp, $str, $match)) {
		assertType('array{string, string}', $match);
	}
}

function bar(string $str): void
{
	$regexp = '/^
            (\w+)        # column type [1]
            [\(]         # (
                ?([\d,]*)  # size or size, precision [2]
            [\)]         # )
            ?\s*         # whitespace
            (\w*)        # extra description (UNSIGNED, CHARACTER SET, ...) [3]
        $/x';
	if (preg_match($regexp, $str, $matches)) {
		// under /x the whitespace is ignored, so "0" matches: non-falsy was an
		// artifact of the whitespace the old grammar read as literal characters
		assertType('array{non-empty-string, non-empty-string, string, string}', $matches);
	}
}

function foobar(string $str): void
{
	$regexp = '/
		# (
		([\d,]*)# a comment immediately behind with a closing parenthesis )
	/x';
	if (preg_match($regexp, $str, $match)) {
		assertType('array{string, string}', $match);
	}
}
