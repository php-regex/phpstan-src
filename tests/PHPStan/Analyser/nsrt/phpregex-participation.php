<?php // lint >= 8.0

namespace PHPRegexParticipation;

use function PHPStan\Testing\assertType;

function acceptVerb(string $s): void {
	// preg_match('/(a)(*ACCEPT)(b)/', 'a') writes ['a','a'] only: the cut
	// leaves group 2 unset
	if (preg_match('/(a)(*ACCEPT)(b)/', $s, $m)) {
		assertType('list{0: string, 1?: string, 2?: string}', $m);
	}
}

function assertionCondition(string $s): void {
	// preg_match('/(?(?=(a))b|(c))/', 'c') writes ['c','','c']: the
	// condition group sits in the array, empty, when the branch fails
	if (preg_match('/(?(?=(a))b|(c))/', $s, $m)) {
		assertType("list{0: non-empty-string, 1?: ''|'a', 2?: 'c'}", $m);
	}
}

function assertionConditionAsNull(string $s): void {
	if (preg_match('/(?(?=(a))b|(c))/', $s, $m, PREG_UNMATCHED_AS_NULL)) {
		assertType("array{non-empty-string, 'a'|null, 'c'|null}", $m);
	}
}

function unmatchedOffsets(string $s): void {
	// preg_match('/(a)(b)*/', 'a', PREG_UNMATCHED_AS_NULL | PREG_OFFSET_CAPTURE)
	// writes group 2 as [null, -1]
	if (preg_match('/(a)(b)*/', $s, $m, PREG_UNMATCHED_AS_NULL | PREG_OFFSET_CAPTURE)) {
		assertType("array{array{non-falsy-string|null, int<0, max>}, array{'a'|null, int<0, max>}, array{'b'|null, int<-1, max>}}", $m);
	}
}
