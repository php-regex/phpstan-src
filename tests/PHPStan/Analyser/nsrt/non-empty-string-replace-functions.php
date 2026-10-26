<?php

namespace NonEmptyStringReplaceFunctions;

use function PHPStan\Testing\assertType;
use str_replace;
use str_ireplace;
use preg_replace;
use substr_replace;

class Foo {
    /**
     * @param string $search
     * @param non-empty-string $replacement
     * @param non-empty-string $subject
     */
    public function replace(string $search, string $replacement, string $subject){
        assertType('non-empty-string', str_replace($search, $replacement, $subject));
        assertType('non-empty-string', str_ireplace($search, $replacement, $subject));

        assertType('string|null', preg_replace($search, $replacement, $subject));

        assertType('non-empty-string', substr_replace($subject, $replacement, 1));
        assertType('non-empty-string', substr_replace($subject, $replacement, -1));
    }

	function foo(float $f) {
		$s = (string) $f;
		assertType('numeric-string&uppercase-string', $s);

		$price = str_replace(',', '.', $s);
		assertType('non-empty-string&uppercase-string', $price);
	}
}

/** @param non-falsy-string $s */
function pregReplaceWritingAFalsyReplacement(string $s): void {
	// the replacement writes '0', which is falsy: the result is non-empty, not non-falsy
	assertType('non-empty-string|null', preg_replace('/a/', '0', $s));
	assertType('non-falsy-string|null', preg_replace('/a/', 'b', $s));
}
