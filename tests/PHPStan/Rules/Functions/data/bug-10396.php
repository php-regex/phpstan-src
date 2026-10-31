<?php // lint >= 8.1 declare(strict_types = 1);

namespace Bug10396;

function test(): string|null {
	$flags = \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL;
	return preg_replace_callback('/bar/', callback(...), 'foobar', -1, $count, $flags);
}

/**
 * @param array<int|string, array{0: string|null, 1: int}> $match
 * @return string
 */
function callback(array $match): string {
	return (string) $match[0][1];
}

/**
 * @param array<int|string, array{0: string, 1: int}> $match
 */
function offsetCallback(array $match): string {
	return $match[0][0];
}

/**
 * @param array<int|string, string|null> $match
 */
function nullableCallback(array $match): string {
	return (string) $match[0];
}

/**
 * @param array<int|string, string> $match
 */
function stringCallback(array $match): string {
	return $match[0];
}

function flags(string $s, int $flags): void {
	preg_replace_callback('/bar/', offsetCallback(...), $s, -1, $count, PREG_OFFSET_CAPTURE);
	preg_replace_callback('/bar/', nullableCallback(...), $s, -1, $count, PREG_UNMATCHED_AS_NULL);
	preg_replace_callback('/bar/', nullableCallback(...), $s);
	preg_replace_callback('/bar/', stringCallback(...), $s);
	preg_replace_callback('/bar/', stringCallback(...), $s, -1, $count, $flags);

	preg_replace_callback('/bar/', stringCallback(...), $s, -1, $count, PREG_OFFSET_CAPTURE);
	preg_replace_callback('/bar/', stringCallback(...), $s, -1, $count, PREG_UNMATCHED_AS_NULL);
	preg_replace_callback('/bar/', offsetCallback(...), $s, -1, $count, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
	preg_replace_callback('/bar/', offsetCallback(...), $s);
	preg_replace_callback('/bar/', callback(...), $s, flags: PREG_UNMATCHED_AS_NULL);
}
