<?php // lint >= 7.4

namespace Bug12126;

use function PHPStan\Testing\assertType;


class HelloWorld
{
	public function sayHello(): void
	{
		$options = ['footest', 'testfoo'];
		$key = array_rand($options, 1);

		$regex = '/foo(?P<test>test)|test(?P<test>foo)/J';
		if (!preg_match_all($regex, $options[$key], $matches, PREG_SET_ORDER)) {
			return;
		}

		assertType('non-empty-list<array{0: string, test?: \'foo\'|\'test\', 1?: \'test\', 2?: \'foo\'}>', $matches);
		if (!preg_match_all($regex, $options[$key], $matches, PREG_PATTERN_ORDER)) {
			return;
		}

		assertType('array{0: non-empty-list<string>, test: non-empty-list<\'\'|\'foo\'|\'test\'>, 1: non-empty-list<\'\'|\'test\'>, 2: non-empty-list<\'\'|\'foo\'>}', $matches);

		if (!preg_match($regex, $options[$key], $matches)) {
			return;
		}

		assertType('array{0: \'footest\'|\'testfoo\', test: \'foo\', 1: \'\', 2: \'foo\'}|array{0: \'footest\'|\'testfoo\', test: \'test\', 1: \'test\'}', $matches);
		// could be assertType("array{0: list<string>, test: 'foo', 1: '', 2: 'foo'}|array{0: list<string>, test: 'test', 1: 'test', 2: ''}", $matches);
	}
}
