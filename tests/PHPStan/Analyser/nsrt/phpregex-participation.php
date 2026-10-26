<?php // lint >= 8.0

namespace PHPRegexParticipation;

use function PHPStan\Testing\assertType;

function acceptVerb(string $s): void {
	// preg_match('/(a)(*ACCEPT)(b)/', 'a') writes ['a','a'] only: the cut
	// leaves group 2 unset
	if (preg_match('/(a)(*ACCEPT)(b)/', $s, $m)) {
		assertType("array{0: string, 1: 'a', 2?: 'b'}", $m);
	}
}

function assertionCondition(string $s): void {
	// preg_match('/(?(?=(a))b|(c))/', 'c') writes ['c','','c']: the
	// condition group sits in the array, empty, when the branch fails
	if (preg_match('/(?(?=(a))b|(c))/', $s, $m)) {
		// the yes branch sets group 1, the no branch writes it empty and sets group 2
		assertType("array{non-empty-string, '', 'c'}|array{non-empty-string, 'a'}", $m);
	}
}

function assertionConditionAsNull(string $s): void {
	if (preg_match('/(?(?=(a))b|(c))/', $s, $m, PREG_UNMATCHED_AS_NULL)) {
		assertType("array{non-empty-string, 'a', null}|array{non-empty-string, null, 'c'}", $m);
	}
}

function unmatchedOffsets(string $s): void {
	// preg_match('/(a)(b)*/', 'a', PREG_UNMATCHED_AS_NULL | PREG_OFFSET_CAPTURE)
	// writes group 2 as [null, -1]
	if (preg_match('/(a)(b)*/', $s, $m, PREG_UNMATCHED_AS_NULL | PREG_OFFSET_CAPTURE)) {
		assertType('array{array{lowercase-string&non-falsy-string, int<0, max>}, array{\'a\', int<0, max>}, array{\'b\'|null, int<-1, max>}}', $m);
	}
}

function nestedOptional(string $s): void {
	// isset($m[1]) proves $m[2]: the engine writes both or neither
	if (preg_match('/(a(b))?/', $s, $m) === 1 && isset($m[1])) {
		\PHPStan\dumpType($m);
		echo $m[2];
	}
}

function duplicateNameAfterTheAlternation(string $s): void {
	// preg_match('/(?:(?<a>x)|(?<b>y))(?:(?<a>z))?/J', 'y') writes
	// ['y', 'a' => '', 1 => '', 'b' => 'y', 2 => 'y']: group 1 is unset but not
	// trailing, so its key is written and the name takes it until group 3 writes it
	if (preg_match('/(?:(?<a>x)|(?<b>y))(?:(?<a>z))?/J', $s, $m)) {
		assertType("array{0: 'x'|'xz'|'y'|'yz', a: ''|'z', 1: '', b: 'y', 2: 'y', 3?: 'z'}|array{0: 'x'|'xz'|'y'|'yz', a: 'x'|'z', 1: 'x', b?: '', 2?: '', 3?: 'z'}", $m);
	}
}

function duplicateNameSetThenOptional(string $s): void {
	// preg_match('/(?<a>x)(?<a>y)?(?<c>q)/J', 'xq') writes ['xq', 'a' => 'x', 'x', '', 'c' => 'q', 'q']:
	// group 2 is unset, so it does not overwrite the name group 1 wrote
	if (preg_match('/(?<a>x)(?<a>y)?(?<c>q)/J', $s, $m)) {
		assertType("array{0: 'xq'|'xyq', a: 'x'|'y', 1: 'x', 2: ''|'y', c: 'q', 3: 'q'}", $m);
	}
}

function casesWhenTheMatchIsNotChecked(string $s): void {
	// the cases of the pattern hold whether or not the call's result is read
	preg_match('/(a(b))?/', $s, $m);
	assertType("array{}|array{''|'ab', 'ab', 'b'}|array{''|'ab'}", $m);
}

function offsetOfAGroupThatMayMatchTheEmptyString(string $s): void {
	// preg_match('/(a*)(b)/', 'b', $m, PREG_OFFSET_CAPTURE) writes [['b', 0], ['', 0], ['b', 0]]:
	// a group that always takes part has an offset, even when it matched nothing
	if (preg_match('/(a*)(b)/', $s, $m, PREG_OFFSET_CAPTURE)) {
		assertType("array{array{lowercase-string&non-falsy-string, int<0, max>}, array{lowercase-string, int<0, max>}, array{'b', int<0, max>}}", $m);
	}
}

function casesOfAnOptionalAlternationWhenTheMatchIsNotChecked(string $s): void {
	// while the call's result is not read, every group stays optional, whatever the cases say
	preg_match('/((a)|(b))?/', $s, $m);
	assertType("array{}|list{0: ''|'a'|'b', 1?: 'a'|'b', 2?: ''|'a', 3?: 'b'}", $m);
}
