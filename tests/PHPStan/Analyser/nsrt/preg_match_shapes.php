<?php // lint >= 7.2

namespace PregMatchShapes;

use function PHPStan\Testing\assertType;
use InvalidArgumentException;

function doMatch(string $s): void {
	if (preg_match('/Price: /i', $s, $matches)) {
		assertType('array{non-falsy-string}', $matches);
	}
	assertType('array{}|array{non-falsy-string}', $matches);

	if (preg_match('/Price: (£|€)\d+/', $s, $matches)) {
		assertType("array{non-falsy-string, '£'|'€'}", $matches);
	} else {
		assertType('array{}', $matches);
	}
	assertType("array{}|array{non-falsy-string, '£'|'€'}", $matches);

	if (preg_match('/Price: (£|€)(\d+)/i', $s, $matches)) {
		assertType('array{non-falsy-string, \'£\'|\'€\', numeric-string}', $matches);
	}
	assertType('array{}|array{non-falsy-string, \'£\'|\'€\', numeric-string}', $matches);

	if (preg_match('  /Price: (£|€)\d+/ i u', $s, $matches)) {
		assertType('array{non-falsy-string, non-falsy-string}', $matches);
	}
	assertType('array{}|array{non-falsy-string, non-falsy-string}', $matches);

	if (preg_match('(Price: (£|€))i', $s, $matches)) {
		assertType('array{non-falsy-string, \'£\'|\'€\'}', $matches);
	}
	assertType('array{}|array{non-falsy-string, \'£\'|\'€\'}', $matches);

	if (preg_match('_foo(.)\_i_i', $s, $matches)) {
		assertType('array{non-falsy-string, non-empty-string}', $matches);
	}
	assertType('array{}|array{non-falsy-string, non-empty-string}', $matches);

	if (preg_match('/(a)(b)*(c)(d)*/', $s, $matches)) {
		assertType('array{0: lowercase-string&non-falsy-string, 1: \'a\', 2: \'\'|\'b\', 3: \'c\', 4?: \'d\'}', $matches);
	}
	assertType('array{}|array{0: lowercase-string&non-falsy-string, 1: \'a\', 2: \'\'|\'b\', 3: \'c\', 4?: \'d\'}', $matches);

	if (preg_match('/(a)(?<name>b)*(c)(d)*/', $s, $matches)) {
		assertType('array{0: lowercase-string&non-falsy-string, 1: \'a\', name: \'\'|\'b\', 2: \'\'|\'b\', 3: \'c\', 4?: \'d\'}', $matches);
	}
	assertType('array{}|array{0: lowercase-string&non-falsy-string, 1: \'a\', name: \'\'|\'b\', 2: \'\'|\'b\', 3: \'c\', 4?: \'d\'}', $matches);

	if (preg_match('/(a)(b)*(c)(?<name>d)*/', $s, $matches)) {
		assertType('array{0: lowercase-string&non-falsy-string, 1: \'a\', 2: \'\'|\'b\', 3: \'c\', name?: \'d\', 4?: \'d\'}', $matches);
	}
	assertType('array{}|array{0: lowercase-string&non-falsy-string, 1: \'a\', 2: \'\'|\'b\', 3: \'c\', name?: \'d\', 4?: \'d\'}', $matches);

	if (preg_match('/(a|b)|(?:c)/', $s, $matches)) {
		assertType("array{0: 'a'|'b'|'c', 1?: 'a'|'b'}", $matches);
	}
	assertType("array{}|array{0: 'a'|'b'|'c', 1?: 'a'|'b'}", $matches);

	if (preg_match('/(foo)(bar)(baz)+/', $s, $matches)) {
		assertType('array{lowercase-string&non-falsy-string, \'foo\', \'bar\', \'baz\'}', $matches);
	}
	assertType('array{}|array{lowercase-string&non-falsy-string, \'foo\', \'bar\', \'baz\'}', $matches);

	if (preg_match('/(foo)(bar)(baz)*/', $s, $matches)) {
		assertType('array{0: lowercase-string&non-falsy-string, 1: \'foo\', 2: \'bar\', 3?: \'baz\'}', $matches);
	}
	assertType('array{}|array{0: lowercase-string&non-falsy-string, 1: \'foo\', 2: \'bar\', 3?: \'baz\'}', $matches);

	if (preg_match('/(foo)(bar)(baz)?/', $s, $matches)) {
		assertType("array{0: 'foobar'|'foobarbaz', 1: 'foo', 2: 'bar', 3?: 'baz'}", $matches);
	}
	assertType("array{}|array{0: 'foobar'|'foobarbaz', 1: 'foo', 2: 'bar', 3?: 'baz'}", $matches);

	if (preg_match('/(foo)(bar)(baz){0,3}/', $s, $matches)) {
		assertType("array{0: 'foobar'|'foobarbaz'|'foobarbazbaz'|'foobarbazbazbaz', 1: 'foo', 2: 'bar', 3?: 'baz'}", $matches);
	}
	assertType("array{}|array{0: 'foobar'|'foobarbaz'|'foobarbazbaz'|'foobarbazbazbaz', 1: 'foo', 2: 'bar', 3?: 'baz'}", $matches);

	if (preg_match('/(foo)(bar)(baz){2,3}/', $s, $matches)) {
		assertType("array{'foobarbazbaz'|'foobarbazbazbaz', 'foo', 'bar', 'baz'}", $matches);
	}
	assertType("array{}|array{'foobarbazbaz'|'foobarbazbazbaz', 'foo', 'bar', 'baz'}", $matches);

	if (preg_match('/(foo)(bar)(baz){2}/', $s, $matches)) {
		assertType("array{'foobarbazbaz', 'foo', 'bar', 'baz'}", $matches);
	}
	assertType("array{}|array{'foobarbazbaz', 'foo', 'bar', 'baz'}", $matches);
}

function doNonCapturingGroup(string $s): void {
	if (preg_match('/Price: (?:£|€)(\d+)/', $s, $matches)) {
		assertType('array{non-falsy-string, numeric-string}', $matches);
	}
	assertType('array{}|array{non-falsy-string, numeric-string}', $matches);
}

function doNamedSubpattern(string $s): void {
	if (preg_match('/\w-(?P<num>\d+)-(\w)/', $s, $matches)) {
		assertType('array{0: non-falsy-string, num: numeric-string, 1: numeric-string, 2: non-empty-string}', $matches);
	}
	assertType('array{}|array{0: non-falsy-string, num: numeric-string, 1: numeric-string, 2: non-empty-string}', $matches);

	if (preg_match('/^(?<name>\S+::\S+)/', $s, $matches)) {
		assertType('array{0: non-falsy-string, name: non-falsy-string, 1: non-falsy-string}', $matches);
	}
	assertType('array{}|array{0: non-falsy-string, name: non-falsy-string, 1: non-falsy-string}', $matches);

	if (preg_match('/^(?<name>\S+::\S+)(?:(?<dataname> with data set (?:#\d+|"[^"]+"))\s\()?/', $s, $matches)) {
		assertType('array{0: non-falsy-string, name: non-falsy-string, 1: non-falsy-string, dataname?: non-falsy-string, 2?: non-falsy-string}', $matches);
	}
	assertType('array{}|array{0: non-falsy-string, name: non-falsy-string, 1: non-falsy-string, dataname?: non-falsy-string, 2?: non-falsy-string}', $matches);
}

function doOffsetCapture(string $s): void {
	if (preg_match('/(foo)(bar)(baz)/', $s, $matches, PREG_OFFSET_CAPTURE)) {
		assertType("array{array{'foobarbaz', int<0, max>}, array{'foo', int<0, max>}, array{'bar', int<0, max>}, array{'baz', int<0, max>}}", $matches);
	}
	assertType("array{}|array{array{'foobarbaz', int<0, max>}, array{'foo', int<0, max>}, array{'bar', int<0, max>}, array{'baz', int<0, max>}}", $matches);
}

function doUnknownFlags(string $s, int $flags): void {
	if (preg_match('/(foo)(bar)(baz)/xyz', $s, $matches, $flags)) {
		assertType('array<array{string|null, int<-1, max>}|string|null>', $matches);
	}
	assertType('array<array{string|null, int<-1, max>}|string|null>', $matches);
}

function doMultipleAlternativeCaptureGroupsWithSameNameWithModifier(string $s): void {
	if (preg_match('/(?J)(?<Foo>[a-z]+)|(?<Foo>[0-9]+)/', $s, $matches)) {
		assertType('array{0: (lowercase-string&non-falsy-string)|numeric-string, Foo: lowercase-string&non-falsy-string, 1: lowercase-string&non-falsy-string}|array{0: (lowercase-string&non-falsy-string)|numeric-string, Foo: numeric-string, 1: \'\', 2: numeric-string}', $matches);
	}
	assertType('array{}|array{0: (lowercase-string&non-falsy-string)|numeric-string, Foo: lowercase-string&non-falsy-string, 1: lowercase-string&non-falsy-string}|array{0: (lowercase-string&non-falsy-string)|numeric-string, Foo: numeric-string, 1: \'\', 2: numeric-string}', $matches);
}

function doMultipleConsecutiveCaptureGroupsWithSameNameWithModifier(string $s): void {
	if (preg_match('/(?J)(?<Foo>[a-z]+)|(?<Foo>[0-9]+)/', $s, $matches)) {
		assertType('array{0: (lowercase-string&non-falsy-string)|numeric-string, Foo: lowercase-string&non-falsy-string, 1: lowercase-string&non-falsy-string}|array{0: (lowercase-string&non-falsy-string)|numeric-string, Foo: numeric-string, 1: \'\', 2: numeric-string}', $matches);
	}
	assertType('array{}|array{0: (lowercase-string&non-falsy-string)|numeric-string, Foo: lowercase-string&non-falsy-string, 1: lowercase-string&non-falsy-string}|array{0: (lowercase-string&non-falsy-string)|numeric-string, Foo: numeric-string, 1: \'\', 2: numeric-string}', $matches);
}

// https://github.com/hoaproject/Regex/issues/31
function hoaBug31(string $s): void {
	if (preg_match('/([\w-])/', $s, $matches)) {
		assertType('array{non-empty-string, non-empty-string}', $matches);
	}
	assertType('array{}|array{non-empty-string, non-empty-string}', $matches);

	if (preg_match('/\w-(\d+)-(\w)/', $s, $matches)) {
		assertType('array{non-falsy-string, numeric-string, non-empty-string}', $matches);
	}
	assertType('array{}|array{non-falsy-string, numeric-string, non-empty-string}', $matches);
}

// https://github.com/phpstan/phpstan/issues/10855#issuecomment-2044323638
function testHoaUnsupportedRegexSyntax(string $s): void {
	if (preg_match('#\QPHPDoc type array<string> of property App\Log::$fillable is not covariant with PHPDoc type array<int, string> of overridden property Illuminate\Database\E\\\\\QEloquent\Model::$fillable.\E#', $s, $matches)) {
		assertType('array{\'PHPDoc type array<string> of property App\\\\Log::$fillable is not covariant with PHPDoc type array<int, string> of overridden property Illuminate\\\\Database\\\\Eloquent\\\\Model::$fillable.\'}', $matches);
	}
	assertType('array{}|array{\'PHPDoc type array<string> of property App\\\\Log::$fillable is not covariant with PHPDoc type array<int, string> of overridden property Illuminate\\\\Database\\\\Eloquent\\\\Model::$fillable.\'}', $matches);
}

function testPregMatchSimpleCondition(string $value): void {
	if (preg_match('/%env\((.*)\:.*\)%/U', $value, $matches)) {
		assertType('array{non-falsy-string, string}', $matches);
	}
}


function testPregMatchIdenticalToOne(string $value): void {
	if (preg_match('/%env\((.*)\:.*\)%/U', $value, $matches) === 1) {
		assertType('array{non-falsy-string, string}', $matches);
	}
}

function testPregMatchIdenticalToOneFalseyContext(string $value): void {
	if (!(preg_match('/%env\((.*)\:.*\)%/U', $value, $matches) !== 1)) {
		assertType('array{non-falsy-string, string}', $matches);
	}
}

function testPregMatchIdenticalToOneInverted(string $value): void {
	if (1 === preg_match('/%env\((.*)\:.*\)%/U', $value, $matches)) {
		assertType('array{non-falsy-string, string}', $matches);
	}
}

function testPregMatchIdenticalToOneFalseyContextInverted(string $value): void {
	if (!(1 !== preg_match('/%env\((.*)\:.*\)%/U', $value, $matches))) {
		assertType('array{non-falsy-string, string}', $matches);
	}
}

function testPregMatchEqualToOne(string $value): void {
	if (preg_match('/%env\((.*)\:.*\)%/U', $value, $matches) == 1) {
		assertType('array{non-falsy-string, string}', $matches);
	}
}

function testPregMatchEqualToOneFalseyContext(string $value): void {
	if (!(preg_match('/%env\((.*)\:.*\)%/U', $value, $matches) != 1)) {
		assertType('array{non-falsy-string, string}', $matches);
	}
}

function testPregMatchEqualToOneInverted(string $value): void {
	if (1 == preg_match('/%env\((.*)\:.*\)%/U', $value, $matches)) {
		assertType('array{non-falsy-string, string}', $matches);
	}
}

function testPregMatchEqualToOneFalseyContextInverted(string $value): void {
	if (!(1 != preg_match('/%env\((.*)\:.*\)%/U', $value, $matches))) {
		assertType('array{non-falsy-string, string}', $matches);
	}
}

function testUnionPattern(string $s): void
{
	if (rand(0,1)) {
		$pattern = '/Price: (\d+)/i';
	} else {
		$pattern = '/Price: (\d+)(\d+)(\d+)/';
	}
	if (preg_match($pattern, $s, $matches)) {
		assertType('array{non-falsy-string, numeric-string, numeric-string, numeric-string}|array{non-falsy-string, numeric-string}', $matches);
	}
	assertType('array{}|array{non-falsy-string, numeric-string, numeric-string, numeric-string}|array{non-falsy-string, numeric-string}', $matches);
}

function doFoo(string $row): void
{
	if (preg_match('~^(a(b))$~', $row, $matches) === 1) {
		assertType("array{'ab', 'ab', 'b'}", $matches);
	}
	if (preg_match('~^(a(b)?)$~', $row, $matches) === 1) {
		assertType("array{0: 'a'|'ab', 1: 'a'|'ab', 2?: 'b'}", $matches);
	}
	if (preg_match('~^(a(b)?)?$~', $row, $matches) === 1) {
		assertType("list{0: ''|'a'|'ab', 1?: 'a'|'ab', 2?: 'b'}", $matches);
	}
}

function doFoo2(string $row): void
{
	if (preg_match('~^((?<branchCode>\\d{1,6})-)?(?<accountNumber>\\d{1,10})/(?<bankCode>\\d{4})$~', $row, $matches) !== 1) {
		return;
	}

	assertType("array{0: non-falsy-string, 1: '', branchCode: '', 2: '', accountNumber: numeric-string, 3: numeric-string, bankCode: non-falsy-string&numeric-string, 4: non-falsy-string&numeric-string}|array{0: non-falsy-string, 1: non-falsy-string, branchCode: numeric-string, 2: numeric-string, accountNumber: numeric-string, 3: numeric-string, bankCode: non-falsy-string&numeric-string, 4: non-falsy-string&numeric-string}", $matches);
}

function doFoo3(string $row): void
{
	if (preg_match('~^(02,([\d.]{10}),(\d+),(\d+),(\d+),)(\d+)$~', $row, $matches) !== 1) {
		return;
	}

	assertType('array{non-falsy-string, non-falsy-string, non-falsy-string, numeric-string, numeric-string, numeric-string, numeric-string}', $matches);
}

function (string $size): void {
	if (preg_match('~^a\.b(c(\d+)(\d+)(\s+))?d~', $size, $matches) !== 1) {
		throw new InvalidArgumentException(sprintf('Invalid size "%s"', $size));
	}
	assertType('array{lowercase-string&non-falsy-string, lowercase-string&non-falsy-string, numeric-string, numeric-string, non-falsy-string}|array{lowercase-string&non-falsy-string}', $matches);
};

function (string $size): void {
	if (preg_match('~^a\.b(c(\d+))?d~', $size, $matches) !== 1) {
		throw new InvalidArgumentException(sprintf('Invalid size "%s"', $size));
	}
	assertType('array{lowercase-string&non-falsy-string, lowercase-string&non-falsy-string, numeric-string}|array{lowercase-string&non-falsy-string}', $matches);
};

function (string $size): void {
	if (preg_match('~^a\.b(c(\d+)?)d~', $size, $matches) !== 1) {
		throw new InvalidArgumentException(sprintf('Invalid size "%s"', $size));
	}
	assertType('array{0: lowercase-string&non-falsy-string, 1: lowercase-string&non-falsy-string, 2?: numeric-string}', $matches);
};

function (string $size): void {
	if (preg_match('~^a\.b(c(\d+)?)?d~', $size, $matches) !== 1) {
		throw new InvalidArgumentException(sprintf('Invalid size "%s"', $size));
	}
	assertType('list{0: lowercase-string&non-falsy-string, 1?: lowercase-string&non-falsy-string, 2?: numeric-string}', $matches);
};

function (string $size): void {
	if (preg_match('~^a\.b(c(\d+))d~', $size, $matches) !== 1) {
		throw new InvalidArgumentException(sprintf('Invalid size "%s"', $size));
	}
	assertType('array{lowercase-string&non-falsy-string, lowercase-string&non-falsy-string, numeric-string}', $matches);
};

function (string $size): void {
	if (preg_match('~^a\.(b)?(c)?d~', $size, $matches) !== 1) {
		throw new InvalidArgumentException(sprintf('Invalid size "%s"', $size));
	}
	assertType("list{0: 'a.bcd'|'a.bd'|'a.cd'|'a.d', 1?: ''|'b', 2?: 'c'}", $matches);
};

function (string $size): void {
	if (preg_match('~^(?:(\\d+)x(\\d+)|(\\d+)|x(\\d+))$~', $size, $matches) !== 1) {
		throw new InvalidArgumentException(sprintf('Invalid size "%s"', $size));
	}
	assertType('array{lowercase-string&non-empty-string, \'\', \'\', \'\', numeric-string}|array{lowercase-string&non-empty-string, \'\', \'\', numeric-string}|array{lowercase-string&non-empty-string, numeric-string, numeric-string}', $matches);
};

function (string $size): void {
	if (preg_match('~^(?:(\\d+)x(\\d+)|(\\d+)|x(\\d+))?$~', $size, $matches) !== 1) {
		throw new InvalidArgumentException(sprintf('Invalid size "%s"', $size));
	}
	assertType('array{lowercase-string, \'\', \'\', \'\', numeric-string}|array{lowercase-string, \'\', \'\', numeric-string}|array{lowercase-string, numeric-string, numeric-string}|array{lowercase-string}', $matches);
};

function (string $size): void {
	if (preg_match('~\{(?:(include)\\s+(?:[$]?\\w+(?<!file))\\s)|(?:(include\\s+file)\\s+(?:[$]?\\w+)\\s)|(?:(include(?:Template|(?:\\s+file)))\\s+(?:\'?.*?\.latte\'?)\\s)~', $size, $matches) !== 1) {
		throw new InvalidArgumentException(sprintf('Invalid size "%s"', $size));
	}
	assertType('array{non-falsy-string, \'\', \'\', non-falsy-string}|array{non-falsy-string, \'\', lowercase-string&non-falsy-string}|array{non-falsy-string, \'include\'}', $matches);
};


function bug11277a(string $value): void
{
	if (preg_match('/^\[(.+,?)*\]$/', $value, $matches)) {
		assertType('array{0: non-falsy-string, 1?: non-empty-string}', $matches);
		if (count($matches) === 2) {
			assertType('array{non-falsy-string, non-empty-string}', $matches);
		}
	}
}

function bug11277b(string $value): void
{
	if (preg_match('/^(?:(.+,?)|(x))*$/', $value, $matches)) {
		assertType("list{0: string, 1?: string, 2?: 'x'}", $matches);
	}
}

// https://www.pcre.org/current/doc/html/pcre2pattern.html#dupgroupnumber
// https://3v4l.org/09qdT
function bug11291(string $s): void {
	if (preg_match('/(?|(a)|(b)(c)|(d)(e)(f))/', $s, $matches)) {
		assertType('list{0: \'a\'|\'bc\'|\'def\', 1: \'a\'|\'b\'|\'d\', 2?: \'c\'|\'e\', 3?: \'f\'}', $matches);
	} else {
		assertType('array{}', $matches);
	}
	assertType('array{}|list{0: \'a\'|\'bc\'|\'def\', 1: \'a\'|\'b\'|\'d\', 2?: \'c\'|\'e\', 3?: \'f\'}', $matches);
}

function bug11323a(string $s): void
{
	if (preg_match('/Price: (?P<currency>£|€)\d+/', $s, $matches)) {
		assertType("array{0: non-falsy-string, currency: '£'|'€', 1: '£'|'€'}", $matches);
	} else {
		assertType('array{}', $matches);
	}
	assertType("array{}|array{0: non-falsy-string, currency: '£'|'€', 1: '£'|'€'}", $matches);
}

function bug11323b(string $s): void
{
	if (preg_match('/Price: (?<currency>£|€)\d+/', $s, $matches)) {
		assertType("array{0: non-falsy-string, currency: '£'|'€', 1: '£'|'€'}", $matches);
	} else {
		assertType('array{}', $matches);
	}
	assertType("array{}|array{0: non-falsy-string, currency: '£'|'€', 1: '£'|'€'}", $matches);
}

function unmatchedAsNullWithMandatoryGroup(string $s): void {
	if (preg_match('/Price: (?<currency>£|€)\d+/', $s, $matches, PREG_UNMATCHED_AS_NULL)) {
		assertType("array{0: non-falsy-string, currency: '£'|'€', 1: '£'|'€'}", $matches);
	} else {
		assertType('array{}', $matches);
	}
	assertType("array{}|array{0: non-falsy-string, currency: '£'|'€', 1: '£'|'€'}", $matches);
}

function (string $s): void {
	if (preg_match('{' . preg_quote('xxx') . '(z)}', $s, $matches)) {
		assertType("array{non-falsy-string, 'z'}", $matches);
	} else {
		assertType('array{}', $matches);
	}
	assertType("array{}|array{non-falsy-string, 'z'}", $matches);
};

function (string $s): void {
	if (preg_match('{' . preg_quote($s) . '(z)}', $s, $matches)) {
		assertType("array{non-falsy-string, 'z'}", $matches);
	} else {
		assertType('array{}', $matches);
	}
	assertType("array{}|array{non-falsy-string, 'z'}", $matches);
};

function (string $s): void {
	if (preg_match('/' . preg_quote($s, '/') . '(\d)/', $s, $matches)) {
		assertType('array{non-empty-string, numeric-string}', $matches);
	} else {
		assertType('array{}', $matches);
	}
	assertType('array{}|array{non-empty-string, numeric-string}', $matches);
};

function (string $s): void {
	if (preg_match('{' . preg_quote($s) . '(z)' . preg_quote($s) . '(?:abc)(def)?}', $s, $matches)) {
		assertType("array{0: non-falsy-string, 1: 'z', 2?: 'def'}", $matches);
	} else {
		assertType('array{}', $matches);
	}
	assertType("array{}|array{0: non-falsy-string, 1: 'z', 2?: 'def'}", $matches);
};

function (string $s, $mixed): void {
	if (preg_match('{' . preg_quote($s) . '(z)' . preg_quote($s) . '(?:abc)'. $mixed .'(def)?}', $s, $matches)) {
		assertType('array<string>', $matches);
	} else {
		assertType('array{}', $matches);
	}
	assertType('array<string>', $matches);
};

function (string $s): void {
	if (preg_match('/^%([0-9]*\$)?[0-9]*\.?[0-9]*([sbdeEfFgGhHouxX])$/', $s, $matches) === 1) {
		assertType("array{non-falsy-string, string, 'b'|'d'|'E'|'e'|'F'|'f'|'G'|'g'|'H'|'h'|'o'|'s'|'u'|'X'|'x'}", $matches);
	}
};

function (string $s): void {
	if (preg_match('~^((\\d{1,6})-)$~', $s, $matches) === 1) {
		assertType("array{non-falsy-string, non-falsy-string, numeric-string}", $matches);
	}
};

function (string $s): void {
	if (preg_match('~^((\\d{1,6}).)$~', $s, $matches) === 1) {
		assertType("array{non-falsy-string, non-falsy-string, numeric-string}", $matches);
	}
};

function (string $s): void {
	if (preg_match('~^([157])$~', $s, $matches) === 1) {
		assertType("array{'1'|'5'|'7', '1'|'5'|'7'}", $matches);
	}
};

function (string $s): void {
	if (preg_match('~^([157XY])$~', $s, $matches) === 1) {
		assertType("array{'1'|'5'|'7'|'X'|'Y', '1'|'5'|'7'|'X'|'Y'}", $matches);
	}
};

function bug11323(string $s): void {
	if (preg_match('/([*|+?{}()]+)([^*|+[:digit:]?{}()]+)/', $s, $matches)) {
		assertType('array{non-falsy-string, non-falsy-string, non-empty-string}', $matches);
	}
	if (preg_match('/\p{L}[[\]]+([-*|+?{}(?:)]+)([^*|+[:digit:]?{a-z}(\p{L})\a-]+)/', $s, $matches)) {
		assertType('array{non-falsy-string, non-falsy-string, non-empty-string}', $matches);
	}
	if (preg_match('{([-\p{L}[\]*|\x03\a\b+?{}(?:)-]+[^[:digit:]?{}a-z0-9#-k]+)(a-z)}', $s, $matches)) {
		assertType("array{non-falsy-string, non-falsy-string, 'a-z'}", $matches);
	}
	if (preg_match('{(\d+)(?i)insensitive((?xs-i)case SENSITIVE here.+and dot matches new lines)}', $s, $matches)) {
		assertType('array{non-falsy-string, numeric-string, non-falsy-string}', $matches);
	}
	if (preg_match('{(\d+)(?i)insensitive((?x-i)case SENSITIVE here(?i:insensitive non-capturing group))}', $s, $matches)) {
		assertType('array{non-falsy-string, numeric-string, non-falsy-string}', $matches);
	}
	if (preg_match('{([]] [^]])}', $s, $matches)) {
		assertType('array{non-falsy-string, non-falsy-string}', $matches);
	}
	if (preg_match('{([[:digit:]])}', $s, $matches)) {
		assertType('array{numeric-string, numeric-string}', $matches);
	}
	if (preg_match('{([\d])(\d)}', $s, $matches)) {
		assertType('array{non-falsy-string&numeric-string, numeric-string, numeric-string}', $matches);
	}
	if (preg_match('{([0-9])}', $s, $matches)) {
		assertType('array{\'0\'|\'1\'|\'2\'|\'3\'|\'4\'|\'5\'|\'6\'|\'7\'|\'8\'|\'9\', \'0\'|\'1\'|\'2\'|\'3\'|\'4\'|\'5\'|\'6\'|\'7\'|\'8\'|\'9\'}', $matches);
	}
	if (preg_match('{(\p{L})(\p{P})(\p{Po})}', $s, $matches)) {
		assertType('array{non-falsy-string, non-empty-string, non-empty-string, non-empty-string}', $matches);
	}
	if (preg_match('{(a)??(b)*+(c++)(d)+?}', $s, $matches)) {
		assertType('array{lowercase-string&non-falsy-string, \'\'|\'a\', \'\'|\'b\', lowercase-string&non-falsy-string, \'d\'}', $matches);
	}
	if (preg_match('{(.\d)}', $s, $matches)) {
		assertType('array{non-falsy-string, non-falsy-string}', $matches);
	}
	if (preg_match('{(\d.)}', $s, $matches)) {
		assertType('array{non-falsy-string, non-falsy-string}', $matches);
	}
	if (preg_match('{(\d\d)}', $s, $matches)) {
		assertType('array{non-falsy-string&numeric-string, non-falsy-string&numeric-string}', $matches);
	}
	if (preg_match('{(.(\d))}', $s, $matches)) {
		assertType('array{non-falsy-string, non-falsy-string, numeric-string}', $matches);
	}
	if (preg_match('{((\d).)}', $s, $matches)) {
		assertType('array{non-falsy-string, non-falsy-string, numeric-string}', $matches);
	}
	if (preg_match('{(\d([1-4])\d)}', $s, $matches)) {
		assertType('array{non-falsy-string&numeric-string, non-falsy-string&numeric-string, \'1\'|\'2\'|\'3\'|\'4\'}', $matches);
	}
	if (preg_match('{(x?([1-4])\d)}', $s, $matches)) {
		assertType('array{lowercase-string&non-falsy-string, lowercase-string&non-falsy-string, \'1\'|\'2\'|\'3\'|\'4\'}', $matches);
	}
	if (preg_match('{([^1-4])}', $s, $matches)) {
		assertType('array{non-empty-string, non-empty-string}', $matches);
	}
	if (preg_match("{([\r\n]+)(\n)([\n])}", $s, $matches)) {
		assertType('array{non-falsy-string, non-falsy-string, "\n", "\n"}', $matches);
	}
	if (preg_match('/foo(*:first)|bar(*:second)([x])/', $s, $matches)) {
		assertType("array{0: 'barx'|'foo', 1?: 'x', MARK?: 'first'|'second'}", $matches);
	}
}

function (string $s): void {
	preg_match('/%a(\d*)/', $s, $matches);
	assertType('array{}|array{lowercase-string&non-falsy-string, \'\'|numeric-string}', $matches);
};

class Bug11376
{
	public function test(string $str): void
	{
		preg_match('~^(?:(\w+)::)?(\w+)$~', $str, $matches);
		assertType("array{}|array{non-empty-string, string, non-empty-string}", $matches);
	}

	public function test2(string $str): void
	{
		if (preg_match('~^(?:(\w+)::)?(\w+)$~', $str, $matches) === 1) {
			assertType('array{non-empty-string, string, non-empty-string}', $matches);
		}
	}
}

function (string $s): void {
	if (rand(0,1)) {
		$p = '/Price: (£)(abc)/';
	} else {
		$p = '/Price: (\d)(b)/';
	}

	if (preg_match($p, $s, $matches)) {
		assertType("array{'Price: £abc', '£', 'abc'}|array{non-falsy-string, numeric-string, 'b'}", $matches);
	}
};

function (string $s): void {
	if (rand(0,1)) {
		$p = '/Price: (£)/';
	} else {
		$p = '/Price: (£|(\d)|(x))/';
	}

	if (preg_match($p, $s, $matches)) {
		assertType("array{0: non-falsy-string, 1: 'x'|'£'|numeric-string, 2?: numeric-string}|array{non-falsy-string, 'x'|'£'|numeric-string, '', 'x'}", $matches);
	}
};

function (string $s): void {
	if (preg_match('/Price: ([a-z])/i', $s, $matches)) {
		assertType("array{non-falsy-string, non-falsy-string}", $matches);
	}
};

function (string $s): void {
	if (preg_match('/Price: ([0-9])/i', $s, $matches)) {
		assertType("array{non-falsy-string, '0'|'1'|'2'|'3'|'4'|'5'|'6'|'7'|'8'|'9'}", $matches);
	}
};

function (string $s): void {
	if (preg_match('/Price: ([xXa])/i', $s, $matches)) {
		assertType("array{non-falsy-string, non-falsy-string}", $matches);
	}
};

function (string $s): void {
	if (preg_match('/Price: ([xXa])/', $s, $matches)) {
		assertType("array{'Price: a'|'Price: X'|'Price: x', 'a'|'X'|'x'}", $matches);
	}
};

function (string $s): void {
	if (preg_match('/Price: (ba[rz])/', $s, $matches)) {
		assertType("array{'Price: bar'|'Price: baz', 'bar'|'baz'}", $matches);
	}
};

function (string $s): void {
	if (preg_match('/Price: (b[ao][mn])/', $s, $matches)) {
		assertType("array{'Price: bam'|'Price: ban'|'Price: bom'|'Price: bon', 'bam'|'ban'|'bom'|'bon'}", $matches);
	}
};

function (string $s): void {
	if (preg_match('/Price: (\s{3}|0)/', $s, $matches)) {
		assertType("array{non-falsy-string, non-empty-string}", $matches);
	}
};

function (string $s): void {
	if (preg_match('/Price: (a|bc?)/', $s, $matches)) {
		assertType("array{'Price: a'|'Price: b'|'Price: bc', 'a'|'b'|'bc'}", $matches);
	}
};

function (string $s): void {
	if (preg_match('/Price: (?<named>a|bc?)/', $s, $matches)) {
		assertType("array{0: 'Price: a'|'Price: b'|'Price: bc', named: 'a'|'b'|'bc', 1: 'a'|'b'|'bc'}", $matches);
	}
};

function (string $s): void {
	if (preg_match('/Price: (a|0c?)/', $s, $matches)) {
		assertType("array{'Price: 0'|'Price: 0c'|'Price: a', '0'|'0c'|'a'}", $matches);
	}
};

function (string $s): void {
	if (preg_match('/Price: (a|\d)/', $s, $matches)) {
		assertType("array{non-falsy-string, 'a'|numeric-string}", $matches);
	}
};

function (string $s): void {
	if (preg_match('/Price: (?<named>a|\d)/', $s, $matches)) {
		assertType("array{0: non-falsy-string, named: 'a'|numeric-string, 1: 'a'|numeric-string}", $matches);
	}
};

function (string $s): void {
	if (preg_match('/Price: (a|0)/', $s, $matches)) {
		assertType("array{'Price: 0'|'Price: a', '0'|'a'}", $matches);
	}
};

function (string $s): void {
	if (preg_match('/Price: (aa|0)/', $s, $matches)) {
		assertType("array{'Price: 0'|'Price: aa', '0'|'aa'}", $matches);
	}
};

function (string $s): void {
	if (preg_match('/( \d+ )/x', $s, $matches)) {
		assertType('array{numeric-string, numeric-string}', $matches);
	}
};

function (string $s): void {
	if (preg_match('/( .? )/x', $s, $matches)) {
		assertType('array{string, string}', $matches);
	}
};

function (string $s): void {
	if (preg_match('/( .* )/x', $s, $matches)) {
		assertType('array{string, string}', $matches);
	}
};

function (string $s): void {
	if (preg_match('/( .+ )/x', $s, $matches)) {
		assertType('array{non-empty-string, non-empty-string}', $matches);
	}
};

function (string $value): void
{
	if (preg_match('/^(x)*$/', $value, $matches, PREG_OFFSET_CAPTURE)) {
		assertType('array{0: array{lowercase-string, int<0, max>}, 1?: array{\'x\', int<0, max>}}', $matches);
	}
};

function (string $value): void {
	if (preg_match('/^(?:(x)|(y))*$/', $value, $matches, PREG_OFFSET_CAPTURE)) {
		assertType('list{0: array{lowercase-string, int<0, max>}, 1?: array{\'\'|\'x\', int<-1, max>}, 2?: array{\'y\', int<0, max>}}', $matches);
	}
};

class Bug11479
{
	static public function sayHello(string $source): void
	{
		$pattern = "~^(?P<dateFrom>\d)?\-?(?P<dateTo>\d)?$~";

		preg_match($pattern, $source, $matches);

		// for $source = "-1" in $matches is
		// array (
		//  0 => '-1',
		//  'dateFrom' => '',
		//  1 => '',
		//  'dateTo' => '1',
		//  2 => '1',
		//)

		assertType("array{}|array{0: string, dateFrom?: ''|numeric-string, 1?: ''|numeric-string, dateTo?: numeric-string, 2?: numeric-string}", $matches);
	}
}

function (string $s): void {
	if (preg_match('~a|(\d)|(\s)~', $s, $matches) === 1) {
		assertType("array{0: non-falsy-string|numeric-string, 1?: numeric-string}|array{non-falsy-string|numeric-string, '', non-falsy-string}", $matches);
	}
};

function (string $s): void {
	if (preg_match('~a|((u)x)|((v)y)~', $s, $matches) === 1) {
		assertType("array{'a'|'ux'|'vy', '', '', 'vy', 'v'}|array{'a'|'ux'|'vy', 'ux', 'u'}|array{'a'|'ux'|'vy'}", $matches);
	}
};

function (string $s): void {
	if (preg_match('~a|(\d)|(\s)~', $s, $matches, PREG_OFFSET_CAPTURE) === 1) {
		assertType("array{0: array{non-falsy-string|numeric-string, int<0, max>}, 1?: array{numeric-string, int<0, max>}}|array{array{non-falsy-string|numeric-string, int<0, max>}, array{'', int<-1, max>}, array{non-falsy-string, int<0, max>}}", $matches);
	}
};

function (string $s): void {
	preg_match('~a|(\d)|(\s)~', $s, $matches);
	assertType("array{}|array{0: non-falsy-string|numeric-string, 1?: numeric-string}|array{non-falsy-string|numeric-string, '', non-falsy-string}", $matches);
};

function bug11490 (string $expression): void {
	$matches = [];

	if (preg_match('/([-+])?([\d]+)%/', $expression, $matches) === 1) {
		assertType("array{non-falsy-string, ''|'+'|'-', numeric-string}", $matches);
	}
}

function bug11490b (string $expression): void {
	$matches = [];

	if (preg_match('/([\\[+])?([\d]+)%/', $expression, $matches) === 1) {
		assertType("array{non-falsy-string, ''|'+'|'[', numeric-string}", $matches);
	}
}

function bug11622 (string $expression): void {
	$matches = [];

	if (preg_match('/^abc(def|$)/', $expression, $matches) === 1) {
		assertType("array{'abc'|'abcdef', ''|'def'}", $matches);
	}
}

function bug11604 (string $string): void {
	if (! preg_match('/(XX)|(YY)?ZZ/', $string, $matches)) {
		return;
	}

	assertType("array{'XX'|'YYZZ'|'ZZ', 'XX'}|list{0: 'XX'|'YYZZ'|'ZZ', 1?: '', 2?: 'YY'}", $matches);
	// could be array{string, '', 'YY'}|array{string, 'XX'}|array{string}
}

function bug11604b (string $string): void {
	if (preg_match('/(XX)|(YY)?(ZZ)/', $string, $matches)) {
		assertType("array{'XX'|'YYZZ'|'ZZ', '', ''|'YY', 'ZZ'}|array{'XX'|'YYZZ'|'ZZ', 'XX'}", $matches);
	}
}

function testLtrimDelimiter (string $string): void {
	if (preg_match(' /(x)/', $string, $matches)) {
		assertType("array{'x', 'x'}", $matches);
	}

	if (preg_match('  /(x)/', $string, $matches)) {
		assertType("array{'x', 'x'}", $matches);
	}
}

function testUnescapeBackslash (string $string): void {
	if (preg_match(<<<'EOD'
		~(\[)~
		EOD, $string, $matches)) {
		assertType("array{'[', '['}", $matches);
	}

	if (preg_match(<<<'EOD'
		~(\d)~
		EOD, $string, $matches)) {
		assertType("array{numeric-string, numeric-string}", $matches);
	}

	if (preg_match(<<<'EOD'
		~(\\d)~
		EOD, $string, $matches)) {
		assertType("array{'\\\\d', '\\\\d'}", $matches);
	}

	if (preg_match(<<<'EOD'
		~(\\\d)~
		EOD, $string, $matches)) {
		assertType("array{non-falsy-string, non-falsy-string}", $matches);
	}

	if (preg_match(<<<'EOD'
		~(\\\\d)~
		EOD, $string, $matches)) {
		assertType("array{'\\\\\\\\d', '\\\\\\\\d'}", $matches);
	}
}

function testEscapedDelimiter (string $string): void {
	if (preg_match(<<<'EOD'
		/(\/)/
		EOD, $string, $matches)) {
		assertType("array{'/', '/'}", $matches);
	}

	if (preg_match(<<<'EOD'
		~(\~)~
		EOD, $string, $matches)) {
		assertType("array{'~', '~'}", $matches);
	}

	if (preg_match(<<<'EOD'
		~(\[2])~
		EOD, $string, $matches)) {
		assertType("array{'[2]', '[2]'}", $matches);
	}

	if (preg_match(<<<'EOD'
		[(\[2\])]
		EOD, $string, $matches)) {
		assertType("array{'[2]', '[2]'}", $matches);
	}

	if (preg_match(<<<'EOD'
		~(\{2})~
		EOD, $string, $matches)) {
		assertType("array{'{2}', '{2}'}", $matches);
	}

	if (preg_match(<<<'EOD'
		{(\{2\})}
		EOD, $string, $matches)) {
		assertType("array{'{2}', '{2}'}", $matches);
	}

	if (preg_match(<<<'EOD'
		~([a\]])~
		EOD, $string, $matches)) {
		assertType("array{']'|'a', ']'|'a'}", $matches);
	}

	if (preg_match(<<<'EOD'
		~([a[])~
		EOD, $string, $matches)) {
		assertType("array{'['|'a', '['|'a'}", $matches);
	}

	if (preg_match(<<<'EOD'
		~([a\]b])~
		EOD, $string, $matches)) {
		assertType("array{']'|'a'|'b', ']'|'a'|'b'}", $matches);
	}

	if (preg_match(<<<'EOD'
		~([a[b])~
		EOD, $string, $matches)) {
		assertType("array{'['|'a'|'b', '['|'a'|'b'}", $matches);
	}

	if (preg_match(<<<'EOD'
		~([a\[b])~
		EOD, $string, $matches)) {
		assertType("array{'['|'a'|'b', '['|'a'|'b'}", $matches);
	}

	if (preg_match(<<<'EOD'
		[([a\[b])]
		EOD, $string, $matches)) {
		assertType("array{'['|'a'|'b', '['|'a'|'b'}", $matches);
	}

	if (preg_match(<<<'EOD'
		{(x\\\{)|(y\\\\\})}
		EOD, $string, $matches)) {
		assertType("array{'x\\\\{'|'y\\\\\\\\}', '', 'y\\\\\\\\}'}|array{'x\\\\{'|'y\\\\\\\\}', 'x\\\\{'}", $matches);
	}
}

function bugUnescapedDashAfterRange (string $string): void
{
	if (preg_match('/([0-1-y])/', $string, $matches)) {
		assertType("array{'-'|'0'|'1'|'y', '-'|'0'|'1'|'y'}", $matches);
	}
}

function bugEmptySubexpression (string $string): void {
	if (preg_match('//', $string, $matches)) {
		assertType("array{''}", $matches);
	}

	if (preg_match('/()/', $string, $matches)) {
		assertType("array{'', ''}", $matches);
	}

	if (preg_match('/|/', $string, $matches)) {
		assertType("array{''}", $matches);
	}

	if (preg_match('~|(a)~', $string, $matches)) {
		assertType("array{0: ''|'a', 1?: 'a'}", $matches);
	}

	if (preg_match('~(a)|~', $string, $matches)) {
		assertType("array{0: ''|'a', 1?: 'a'}", $matches);
	}

	if (preg_match('~(a)||(b)~', $string, $matches)) {
		assertType("array{''|'a'|'b', '', 'b'}|array{0: ''|'a'|'b', 1?: 'a'}", $matches);
	}

	if (preg_match('~(|(a))~', $string, $matches)) {
		assertType("array{0: ''|'a', 1: ''|'a', 2?: 'a'}", $matches);
	}

	if (preg_match('~((a)|)~', $string, $matches)) {
		assertType("array{0: ''|'a', 1: ''|'a', 2?: 'a'}", $matches);
	}

	if (preg_match('~((a)||(b))~', $string, $matches)) {
		assertType("array{''|'a'|'b', ''|'a'|'b', '', 'b'}|array{0: ''|'a'|'b', 1: ''|'a'|'b', 2?: 'a'}", $matches);
	}

	if (preg_match('~((a)|()|(b))~', $string, $matches)) {
		assertType("array{''|'a'|'b', ''|'a'|'b', 'a'}|array{0: ''|'a'|'b', 1: ''|'a'|'b', 2: '', 3: '', 4?: 'b'}", $matches);
	}
}

function bug11744(string $string): void
{
	if (!preg_match('~^((/[a-z]+)?)~', $string, $matches)) {
		return;
	}
	assertType('array{0: lowercase-string, 1: lowercase-string, 2?: lowercase-string&non-falsy-string}', $matches);

	if (!preg_match('~^((/[a-z]+)?.*)~', $string, $matches)) {
		return;
	}
	assertType('array{0: string, 1: string, 2?: lowercase-string&non-falsy-string}', $matches);

	if (!preg_match('~^((/[a-z]+)?.+)~', $string, $matches)) {
		return;
	}
	assertType('array{0: non-empty-string, 1: non-empty-string, 2?: lowercase-string&non-falsy-string}', $matches);
}

function bug12749(string $str): void
{
	if (preg_match('/[A-Z]/', $str, $match)) {
		assertType('array{\'A\'|\'B\'|\'C\'|\'D\'|\'E\'|\'F\'|\'G\'|\'H\'|\'I\'|\'J\'|\'K\'|\'L\'|\'M\'|\'N\'|\'O\'|\'P\'|\'Q\'|\'R\'|\'S\'|\'T\'|\'U\'|\'V\'|\'W\'|\'X\'|\'Y\'|\'Z\'}', $match); // the wish granted and exceeded: the constants enumerate
	}
}

function bug12749a(string $str): void
{
	if (preg_match('/[A-Z]{2,}/', $str, $match)) {
		assertType('array{non-falsy-string&uppercase-string}', $match);
	}
}

function bug12749b(string $str): void
{
	if (preg_match('/[0-9][A-Z]/', $str, $match)) {
		assertType('array{non-falsy-string&uppercase-string}', $match);
	}
}

function bug12749c(string $str): void
{
	if (preg_match('/[0-9][A-Z]?/', $str, $match)) {
		assertType('array{non-empty-string&uppercase-string}', $match);
	}
}

function bug12749d(string $str): void
{
	if (preg_match('/[0-9]?[A-Z]/', $str, $match)) {
		assertType('array{non-falsy-string&uppercase-string}', $match);
	}
}

function bug12749e(string $str): void
{
	// no ^ $ delims, therefore can be anything which contains a number
	if (preg_match('/[0-9]/', $str, $match)) {
		assertType('array{\'0\'|\'1\'|\'2\'|\'3\'|\'4\'|\'5\'|\'6\'|\'7\'|\'8\'|\'9\'}', $match);
	}
}

function bug12749f(string $str): void
{
	if (preg_match('/^[0-9]$/', $str, $match)) {
		assertType('array{\'0\'|\'1\'|\'2\'|\'3\'|\'4\'|\'5\'|\'6\'|\'7\'|\'8\'|\'9\'}', $match); // the wish granted and exceeded: the digits enumerate
	}
}

function bug12397(string $string): void {
	$m = preg_match('#\b([A-Z]{2,})-(\d+)#', $string, $match);
	assertType('array{}|array{non-falsy-string&uppercase-string, non-falsy-string&uppercase-string, numeric-string}', $match);
}

function bug12792(string $string): void {
	if (preg_match('~a\Kb~', $string, $match) === 1) {
		assertType('array{string}', $match);
	}

	if (preg_match('~a\K~', $string, $match) === 1) {
		assertType('array{string}', $match);
	}

	if (preg_match('~a\K.+~', $string, $match) === 1) {
		assertType('array{string}', $match);
	}

	if (preg_match('~a\K.*~', $string, $match) === 1) {
		assertType('array{string}', $match);
	}

	if (preg_match('~a\K(.+)~', $string, $match) === 1) {
		assertType('array{string, non-empty-string}', $match);
	}

	if (preg_match('~a\K(.*)~', $string, $match) === 1) {
		assertType('array{string, string}', $match);
	}

	if (preg_match('~a\K(.+?)~', $string, $match) === 1) {
		assertType('array{string, non-empty-string}', $match);
	}

	if (preg_match('~a\K(.*?)~', $string, $match) === 1) {
		assertType('array{string, string}', $match);
	}

	if (preg_match('~a\K(?=.+)~', $string, $match) === 1) {
		assertType('array{string}', $match);
	}

	if (preg_match('~a\K(?=.*)~', $string, $match) === 1) {
		assertType('array{string}', $match);
	}

	if (preg_match('~a(?:x\Kb|c)~', $string, $match) === 1) {
		assertType('array{string}', $match);
	}

	if (preg_match('~a(?:c|x\Kb)~', $string, $match) === 1) {
		assertType('array{string}', $match);
	}

	if (preg_match('~a(y|(?:x\Kb|c))d~', $string, $match) === 1) {
		assertType('array{string, \'c\'|\'xb\'|\'y\'}', $match);
	}

	if (preg_match('~a((?:c|x\Kb)|y)d~', $string, $match) === 1) {
		assertType('array{string, \'c\'|\'xb\'|\'y\'}', $match);
	}
}

function testExtendedSyntaxEscapedHash(string $string): void {
	// \# in extended mode should be treated as literal hash, not comment
	if (preg_match('/^ ([\#.]) $/x', $string, $matches)) {
		assertType("array{'#'|'.', '#'|'.'}", $matches);
	}

	// Real comment vs escaped hash
	if (preg_match('/
		(\d+)      # this is a comment
		([\#ab]+)  # hash in character class (escaped)
	/x', $string, $matches)) {
		assertType('array{lowercase-string&non-falsy-string, numeric-string, lowercase-string&non-falsy-string}', $matches);
	}
}

function nestedCapturingParent(string $s): void {
	if (preg_match('/(x(?:(b)))?c/', $s, $matches)) {
		assertType("array{'c'|'xbc', 'xb', 'b'}|array{'c'|'xbc'}", $matches);
	}
}

function branchResetInEveryBranch(string $s): void {
	if (preg_match('/(?|(a)|(b))(c)?/', $s, $matches)) {
		assertType("array{0: 'a'|'ac'|'b'|'bc', 1: 'a'|'b', 2?: 'c'}", $matches);
	}
	if (preg_match('/(?|(a)(x)|(b)(y))/', $s, $matches)) {
		assertType("array{'ax'|'by', 'a'|'b', 'x'|'y'}", $matches);
	}
}

function unmatchedOffsetOfOnlyOptionalGroup(string $s): void {
	// preg_match('/(a)?/', '', $m, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL) writes group 1 as [null, -1]
	if (preg_match('/(a)?/', $s, $matches, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL)) {
		assertType("array{array{''|'a', int<0, max>}, array{'a'|null, int<-1, max>}}", $matches);
	}
}

function unmatchedNameWithOffsets(string $s): void {
	// preg_match('/(?<a>x)|(?<b>y)/', 'y', $m, PREG_OFFSET_CAPTURE) writes 'a' as ['', -1]
	if (preg_match('/(?<a>x)|(?<b>y)/', $s, $matches, PREG_OFFSET_CAPTURE)) {
		assertType("array{0: array{'x'|'y', int<0, max>}, a: array{'', int<-1, max>}, 1: array{'', int<-1, max>}, b: array{'y', int<0, max>}, 2: array{'y', int<0, max>}}|array{0: array{'x'|'y', int<0, max>}, a: array{'x', int<0, max>}, 1: array{'x', int<0, max>}}", $matches);
	}
}

function caretBranchBeforeAFailingTail(string $s): void {
	// preg_match('/(^|a)b/', 'ab') sets group 1 to 'a': the caret branch loses when 'b' fails after it
	if (preg_match('/(^|a)b/', $s, $matches)) {
		assertType("array{'ab'|'b', ''|'a'}", $matches);
	}
}

function caretBranchFromAnOffset(string $s, int $offset): void {
	if (preg_match('/^|bar/', $s, $matches)) {
		assertType("array{''}", $matches);
	}
	// preg_match('/^|bar/', 'xbar', $m, 0, 1) sets $m[0] to 'bar': the caret does not match at an offset
	if (preg_match('/^|bar/', $s, $matches, 0, 1)) {
		assertType("array{''|'bar'}", $matches);
	}
	if (preg_match('/^|bar/', $s, $matches, 0, $offset)) {
		assertType("array{''|'bar'}", $matches);
	}
}

function groupsUnsetAfterAccept(string $s): void {
	// preg_match('/(?:a(*ACCEPT))?(b)(c)/', 'a') writes ['a']: both groups after the cut are left out
	if (preg_match('/(?:a(*ACCEPT))?(b)(c)/', $s, $matches)) {
		assertType("list{0: string, 1?: ''|'b', 2?: 'c'}", $matches);
	}
	if (preg_match('/(?:a(*ACCEPT))?(b)(c)/', $s, $matches, PREG_UNMATCHED_AS_NULL)) {
		assertType("array{string, 'b'|null, 'c'|null}", $matches);
	}
	// preg_match('/(a)|b(*ACCEPT)(c)/', 'b') writes ['b']: the branch is cut before its group
	if (preg_match('/(a)|b(*ACCEPT)(c)/', $s, $matches)) {
		assertType("array{string, 'a'}|list{0: string, 1?: '', 2?: 'c'}", $matches);
	}
}

function repeatedAlternationKeepsTheGroupsOfEarlierRounds(string $s): void {
	// preg_match('/(?:(a)|(b))+/', 'ab') writes ['ab', 'a', 'b']: a later round does not unset the groups of an earlier one
	if (preg_match('/(?:(a)|(b))+/', $s, $matches)) {
		assertType('list{0: lowercase-string&non-falsy-string, 1?: \'\'|\'a\', 2?: \'b\'}', $matches);
	}
	if (preg_match('/(?:x(a)|y(b)){2}/', $s, $matches)) {
		assertType("list{0: 'xaxa'|'xayb'|'ybxa'|'ybyb', 1?: ''|'a', 2?: 'b'}", $matches);
	}
}

function inlineCaselessOptionKeepsItsScope(string $s): void {
	// preg_match('/(?i:a)(b)/', 'aB') is 0: the option holds inside its group only
	if (preg_match('/(?i:a)(b)/', $s, $matches)) {
		assertType("array{non-falsy-string, 'b'}", $matches);
	}
	// preg_match('/((?i)a)(b)/', 'aB') is 0: a bare option holds to the end of its group
	if (preg_match('/((?i)a)(b)/', $s, $matches)) {
		assertType("array{non-falsy-string, non-falsy-string, 'b'}", $matches);
	}
}

function testAssertionsAreZeroWidth(string $s): void {
	// from php-src ext/pcre/tests/bug66121.phpt
	if (preg_match('/(?<!k)/u', $s, $matches)) {
		assertType("array{''}", $matches);
	}

	// from PCRE2 testdata/testinput1
	if (preg_match('/(?=C)/', $s, $matches)) {
		assertType("array{''}", $matches);
	}

	// from PCRE2 testdata/testinput1
	if (preg_match('/(\.\d\d((?=0)|\d(?=\d)))/', $s, $matches)) {
		assertType("array{non-falsy-string, non-falsy-string, ''|numeric-string}", $matches);
	}

	// from PCRE2 testdata/testinput1
	if (preg_match('/$(?<=^(a))/', $s, $matches)) {
		assertType("array{'', 'a'}", $matches);
	}
}

function testGroupsInNegativeAssertionsAreNeverSet(string $s): void {
	// from PCRE2 testdata/testinput1
	if (preg_match('/(?<!(c|d))[ab]/', $s, $matches)) {
		assertType("array{'a'|'b'}", $matches);
	}

	// preg_match('/(?!(a))b/', 'b') writes ['b']
	if (preg_match('/(?!(a))b/', $s, $matches)) {
		assertType("array{'b'}", $matches);
	}
}

function testDuplicateNamesInternalOption(string $s): void {
	// from php-src ext/pcre/tests/bug79257.phpt
	if (preg_match('/(?J)(?:(?<g>foo)|(?<g>bar))(?<h>baz)/', $s, $matches)) {
		assertType("array{0: 'barbaz'|'foobaz', g: 'bar', 1: '', 2: 'bar', h: 'baz', 3: 'baz'}|array{0: 'barbaz'|'foobaz', g: 'foo', 1: 'foo', 2: '', h: 'baz', 3: 'baz'}", $matches);
	}

	// from php-src ext/pcre/tests/bug79257.phpt
	if (preg_match('/(?J)(?:(?<g>foo)|(?<g>bar))/', $s, $matches, PREG_UNMATCHED_AS_NULL)) {
		assertType("array{0: 'bar'|'foo', g: 'bar'|'foo'|null, 1: 'foo'|null, 2: 'bar'|null}", $matches);
	}

	// from php-src ext/pcre/tests/bug79257.phpt
	if (preg_match_all('/(?J)(?<chr>[ac])(?<num>\d)|(?<chr>[b])/', $s, $matches, PREG_SET_ORDER)) {
		assertType("non-empty-list<array{0: string, chr?: ''|'a'|'b'|'c', 1?: ''|'a'|'c', num?: ''|numeric-string, 2?: ''|numeric-string, 3?: 'b'}>", $matches);
	}
}

function testQuantifiedMaybeEmptyAtom(string $s): void {
	// from PCRE2 testdata/testinput6
	if (preg_match('/(abc|)+/', $s, $matches)) {
		assertType('array{lowercase-string, \'\'|\'abc\'}', $matches);
	}

	// from PCRE2 testdata/testinput6
	if (preg_match('/^(){3,5}/', $s, $matches)) {
		assertType("array{'', ''}", $matches);
	}

	// from PCRE2 testdata/testinput1
	if (preg_match('/((a|)+)+Z/', $s, $matches)) {
		assertType('array{non-falsy-string, lowercase-string, \'\'|\'a\'}', $matches);
	}
}

function testBackreferences(string $s): void {
	// from PCRE2 testdata/testinput1
	if (preg_match('/([a-c]*)\1/', $s, $matches)) {
		assertType('array{string, lowercase-string}', $matches);
	}

	// from PCRE2 testdata/testinput1
	if (preg_match('/(\2|a)(\1)/', $s, $matches)) {
		assertType("array{string, string, string}", $matches);
	}

	// from PCRE2 testdata/testinput2
	if (preg_match('/^(..)(\1{2,3})ab/', $s, $matches)) {
		assertType("array{non-falsy-string, non-falsy-string, string}", $matches);
	}
}

function testBranchResetNotInOnlyTopLevelAlternation(string $s): void {
	// from PCRE2 testdata/testinput2
	if (preg_match('/(x)(?|(abc)|(xyz))(x)/', $s, $matches)) {
		assertType("array{'xabcx'|'xxyzx', 'x', 'abc'|'xyz', 'x'}", $matches);
	}
}

function testConditionalSubpatterns(string $s): void {
	// from PCRE2 testdata/testinput1
	if (preg_match('/^(\()?blah(?(1)(\)))$/', $s, $matches)) {
		assertType("list{0: non-falsy-string, 1?: ''|'(', 2?: ')'}", $matches);
	}

	// from PCRE2 testdata/testinput2: the condition reads the group it sits in, which is
	// not set yet, so the group always matches the empty string
	if (preg_match('/((?(-1)a))/', $s, $matches)) {
		assertType("array{'', ''}", $matches);
	}

	// from PCRE2 testdata/testinput1
	if (preg_match('/(?(DEFINE)(?<foo>bar))(?<![-a-z0-9])word/', $s, $matches)) {
		assertType("array{'word'}", $matches);
	}

	// the engine never sets a group of (?(DEFINE)...): its key holds the unmatched value
	if (preg_match('/(?(DEFINE)(?<foo>bar))(?<![-a-z0-9])word/', $s, $matches, PREG_UNMATCHED_AS_NULL)) {
		assertType("array{0: 'word', foo: null, 1: null}", $matches);
	}
	if (preg_match('/(?(DEFINE)(?<foo>bar))(x)?y/', $s, $matches)) {
		assertType("array{0: 'xy'|'y', foo?: '', 1?: '', 2?: 'x'}", $matches);
	}
}
