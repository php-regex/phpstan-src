<?php // lint >= 8.0

namespace Bug11438;

use function PHPStan\Testing\assertType;

function (string $subject): void {
	preg_replace_callback_array(
		[
			'~A([a]+)~i' => function ($match) {
				assertType('array{non-falsy-string, non-falsy-string}', $match);
				return '';
			},
			'~B([b]+)?~i' => function ($match) {
				assertType('array{0: non-falsy-string, 1?: non-falsy-string}', $match);
				return '';
			},
			'/(foo)?(bar)?(baz)?/' => function ($match) {
				assertType("list{0: ''|'bar'|'barbaz'|'baz'|'foo'|'foobar'|'foobarbaz'|'foobaz', 1?: ''|'foo', 2?: ''|'bar', 3?: 'baz'}", $match);
				return '';
			},
		],
		$subject,
	);
};

function (string $subject): void {
	preg_replace_callback_array(
		[
			'/(\d+)-(x)?/' => fn ($match) => assertType('array{lowercase-string&non-falsy-string, numeric-string, \'x\'|null}', $match),
		],
		$subject,
		-1,
		$count,
		PREG_UNMATCHED_AS_NULL,
	);

	preg_replace_callback_array(
		[
			'/(\d+)-(x)?/' => fn ($match) => assertType('array{0: array{lowercase-string&non-falsy-string, int<0, max>}, 1: array{numeric-string, int<0, max>}, 2?: array{\'x\', int<0, max>}}', $match),
		],
		$subject,
		flags: PREG_OFFSET_CAPTURE,
	);
};

function (string $subject, string $pattern, int $flags): void {
	preg_replace_callback_array(
		[
			'/(a)/' => fn ($match) => assertType('mixed', $match),
			$pattern => fn ($match) => assertType('mixed', $match),
		],
		$subject,
	);

	preg_replace_callback_array(
		[
			'/(a)/' => fn ($match) => assertType('mixed', $match),
		],
		$subject,
		-1,
		$count,
		$flags,
	);
};
