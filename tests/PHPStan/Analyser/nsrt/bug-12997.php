<?php

namespace Bug12997;

use function PHPStan\Testing\assertType;

class HelloWorld
{

	/**
	 * @param non-empty-string $nonES
	 * @param non-falsy-string $nonFalsy
	 * @param non-empty-string $replacement
	 * @param array<non-empty-string> $array
	 */
	public function sayHello($nonES, $nonFalsy, $replacement, array $array): void
	{
		$stripped = preg_replace('{.*<script[^>]*>(?:\s*<!--)?(.*?)(?:-->\s*)?<\/?script[^>]*>.*}is', '$1', $nonES);
		assertType('string|null', $stripped);

		assertType('non-empty-string|null', preg_replace('/a/', 'b', $nonES));

		// the group can be unset or missing
		assertType('string|null', preg_replace('/a(b)?/', '$1', $nonES));
		assertType('string|null', preg_replace('/a(b)?/', '${1}', $nonES));
		assertType('string|null', preg_replace('/a(b)?/', '\1', $nonES));
		assertType('string|null', preg_replace('/a/', '$2', $nonES));
		assertType('array<string>', preg_replace('/a(b)?/', '$1', $array));

		// the group always matches some text
		assertType('non-empty-string|null', preg_replace('/(a+)/', '$1', $nonES));
		assertType('non-empty-string|null', preg_replace('/a/', '$0', $nonES));

		// the text around the reference
		assertType('non-empty-string|null', preg_replace('/a(b)?/', '<$1>', $nonES));
		assertType('non-falsy-string|null', preg_replace('/a(b)?/', '<$1>', $nonFalsy));
		assertType('non-empty-string|null', preg_replace('/a(b)?/', '0$1', $nonFalsy));

		// escaped and incomplete references are text
		assertType('non-empty-string|null', preg_replace('/a/', '\$1', $nonES));
		assertType('non-falsy-string|null', preg_replace('/a/', '\\\\$1', $nonFalsy));
		assertType('non-empty-string|null', preg_replace('/a/', '${1', $nonES));

		// a replacement not known can hold a reference
		assertType('string|null', preg_replace('/a(b)?/', $replacement, $nonES));
	}

}
