<?php

namespace Bug11602Regex;

use function PHPStan\Testing\assertType;

class HelloWorld
{

	public function parseRef(string $coordinate, string $ref): string
	{
		if (preg_match('/^([A-Z]{1,3})([0-9]{1,7})(:([A-Z]{1,3})([0-9]{1,7}))?$/', $ref, $matches) !== 1) {
			return $ref;
		}
		assertType('array{non-falsy-string&uppercase-string, non-falsy-string&uppercase-string, numeric-string, non-falsy-string&uppercase-string, non-falsy-string&uppercase-string, numeric-string}|array{non-falsy-string&uppercase-string, non-falsy-string&uppercase-string, numeric-string}', $matches);
		if (!isset($matches[3])) {
			return $coordinate;
		}
		assertType('numeric-string', $matches[5]);
		assertType('non-falsy-string&uppercase-string', $matches[4]);

		return $matches[1] . $matches[2] . ':' . $matches[4] . $matches[5];
	}

}
