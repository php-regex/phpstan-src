<?php

namespace Bug11746;

use function PHPStan\Testing\assertType;

class Foo
{

	public function typeWithReference(string $str): void
	{
		if (preg_match('~(?<type>a)(?:&(?<type2>(?&type)))?~', $str, $matches) !== 1) {
			return;
		}

		assertType("array{0: 'a'|'a&a', type: 'a', 1: 'a', type2?: 'a', 2?: 'a'}", $matches);
	}

	public function typeConstTopLevel(string $str): void
	{
		if (preg_match('~a~', $str, $matches) !== 1) {
			return;
		}

		assertType("array{'a'}", $matches);
	}

	public function typeWithCommentWithSpecialCharacters(string $str): void
	{
		if (preg_match(<<<'EOD'
			~(?:(?x)
				   a # https://3v4l.org/igvia !"#$%&'()*+,-./:;<=>?@[\]^_`{|}\~
			)~
			EOD, $str, $matches) !== 1) {
			return;
		}

		assertType("array{'a'}", $matches);
	}

}
