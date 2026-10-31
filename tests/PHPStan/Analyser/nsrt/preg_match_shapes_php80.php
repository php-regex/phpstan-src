<?php // lint >= 8.0

namespace PregMatchShapesPhp82;

use function PHPStan\Testing\assertType;

function doOffsetCaptureWithUnmatchedNull(string $s): void {
	// see https://3v4l.org/07rBO#v8.2.9
	if (preg_match('/(foo)(bar)(baz)/', $s, $matches, PREG_OFFSET_CAPTURE|PREG_UNMATCHED_AS_NULL)) {
		assertType("array{array{'foobarbaz', int<0, max>}, array{'foo', int<0, max>}, array{'bar', int<0, max>}, array{'baz', int<0, max>}}", $matches);
	}
	assertType("array{}|array{array{'foobarbaz', int<0, max>}, array{'foo', int<0, max>}, array{'bar', int<0, max>}, array{'baz', int<0, max>}}", $matches);
}

function doNonAutoCapturingModifier(string $s): void {
	if (preg_match('/(?n)(\d+)/', $s, $matches)) {
		// (?n) turns capturing off, so only the full match remains
		assertType('array{numeric-string}', $matches);
	}
	assertType('array{}|array{numeric-string}', $matches);
}
