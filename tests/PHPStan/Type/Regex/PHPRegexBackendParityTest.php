<?php declare(strict_types = 1);

namespace PHPStan\Type\Regex;

use Closure;
use PHPStan\Php\PcreVersion;
use PHPStan\Php\PhpVersion;
use PHPStan\Type\VerbosityLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use function array_keys;
use function ctype_digit;
use function explode;
use function is_int;
use function json_encode;
use function preg_match;
use function str_contains;
use function version_compare;
use const PCRE_VERSION;
use const PHP_VERSION_ID;
use const PREG_UNMATCHED_AS_NULL;

/**
 * Matches each pattern with the engine and checks the group count, the values
 * and the optionality of the shape against what the engine writes.
 */
final class PHPRegexBackendParityTest extends TestCase
{

	/**
	 * @return iterable<string, array{pattern: string, subject: string}>
	 */
	public static function providePatterns(): iterable
	{
		yield 'plain groups' => ['pattern' => '/(foo)(bar)(baz)+/', 'subject' => 'foobarbazbaz'];
		yield 'backreference' => ['pattern' => '/(a|b)\1/', 'subject' => 'aa'];
		yield 'mark verb' => ['pattern' => '/(*MARK:first)a/', 'subject' => 'a'];
		yield 'quoted run' => ['pattern' => '/(\Qa.c\E)/', 'subject' => 'a.c'];
		yield 'extended mode' => ['pattern' => '/(a) # comment/x', 'subject' => 'a'];
		yield 'duplicate names' => ['pattern' => '/(?J)(?<x>a)|(?<x>b)/', 'subject' => 'b'];
		// spaces inside \x{} need PCRE2 10.43, which a CI image linking the system's PCRE2 may not have
		if (version_compare(explode(' ', PCRE_VERSION, 2)[0], '10.43', '>=')) {
			yield 'version-shaped escape' => ['pattern' => '/(a\x{ 41 })/', 'subject' => 'aA'];
		}
		yield 'digit runs' => ['pattern' => '/(?<year>\d{4})-(?<month>\d{2})/', 'subject' => '2026-10'];
		yield 'conditional' => ['pattern' => '/(a)(?(1)b|(c))/', 'subject' => 'ab'];
		yield 'define' => ['pattern' => '/(?(DEFINE)(?<d>abc))(?<r>x)/', 'subject' => 'x'];
		yield 'subroutine call' => ['pattern' => '/(a)(?1)/', 'subject' => 'aa'];
	}

	#[DataProvider('providePatterns')]
	public function testEngineMatches(string $pattern, string $subject): void
	{
		// PREG_UNMATCHED_AS_NULL keeps every numbered group in $matches —
		// the engine omits the trailing ones it never filled, and the count
		// would lie
		$matched = preg_match($pattern, $subject, $matches, PREG_UNMATCHED_AS_NULL);
		self::assertSame(1, $matched, $pattern . ' must match ' . $subject);

		$helper = (new ReflectionClass(RegexExpressionHelper::class))->newInstanceWithoutConstructor();
		$parser = new RegexGroupParser(
			new PhpVersion(PHP_VERSION_ID),
			new PHPRegexReader(new PhpVersion(PHP_VERSION_ID), PcreVersion::fromRuntime(), $helper),
		);
		$this->resetParsedAstCache();
		$result = $parser->parseGroups($pattern);
		self::assertNotNull($result, $pattern . ' must read whole');

		$engineGroups = 0;
		foreach (array_keys($matches) as $key) {
			if (!is_int($key) || $key <= $engineGroups) {
				continue;
			}

			$engineGroups = $key;
		}

		$shapeGroups = $result->getCapturingGroups();
		self::assertCount($engineGroups, $shapeGroups, $pattern . ': the engine numbers as many groups as the walk');

		foreach ($shapeGroups as $group) {
			$number = $group->getId();
			$written = $matches[$number] ?? null;

			// a group the engine left unset is optional in the shape; a
			// duplicate name writes the value of whichever branch ran
			if ($written === null) {
				self::assertTrue($group->isOptional(), $pattern . ': group ' . $number . ' never filled must read optional');

				continue;
			}

			if ($group->getName() !== null && $matches[$group->getName()] !== null) {
				self::assertSame($written, $matches[$group->getName()], $pattern . ': the name writes the same value');
			}

			$type = $group->getType()->describe(VerbosityLevel::precise());
			if ($type !== 'string' && str_contains($type, "'")) {
				self::assertStringContainsString(
					"'" . $written . "'",
					$type,
					$pattern . ': the engine wrote ' . $written . ', the shape reads ' . $type,
				);
			} elseif ($type !== 'string') {
				self::assertNotSame('', $written, $pattern . ': an inexact shape never claims constants');
			}
		}
	}

	public function testConditional(): void
	{
		// preg_match('/(a)(?(1)b|(c))/', 'ab', $m) === ['ab', 'a']: the else
		// group is numbered, optional, and never set beside a set condition;
		// the shape says as much, so the engine's answer is compared as text
		self::assertSame(1, preg_match('/(a)(?(1)b|(c))/', 'ab', $m));
		self::assertSame('["ab","a"]', json_encode($m));

		$parser = $this->createParser();
		$this->resetParsedAstCache();
		$result = $parser->parseGroups('/(a)(?(1)b|(c))/');
		self::assertNotNull($result);

		$else = $result->getCapturingGroups()[2] ?? null;
		self::assertNotNull($else);
		self::assertTrue($else->isOptional(), 'the else branch runs alone, or not at all');
	}

	public function testDefinedGroup(): void
	{
		// preg_match('/(?(DEFINE)(?<d>abc))(?<r>x)/', 'x', $m): d and 1 stay
		// at the empty string, whatever the block spells
		self::assertSame(1, preg_match('/(?(DEFINE)(?<d>abc))(?<r>x)/', 'x', $m));
		self::assertSame('{"0":"x","d":"","1":"","r":"x","2":"x"}', json_encode($m), 'the engine leaves the defined group empty');

		$parser = $this->createParser();
		$this->resetParsedAstCache();
		$result = $parser->parseGroups('/(?(DEFINE)(?<d>abc))(?<r>x)/');
		self::assertNotNull($result);

		$defined = $result->getCapturingGroups()[1] ?? null;
		self::assertNotNull($defined);
		self::assertSame("''", $defined->getType()->describe(VerbosityLevel::precise()));
	}

	public function testDigitGroupLengths(): void
	{
		self::assertSame(1, preg_match('/(?<year>\d{4})-(?<month>\d{2})/', '2026-10', $m));
		self::assertSame('2026', $m['year']);
		self::assertTrue(ctype_digit($m['year']));

		$parser = $this->createParser();
		$this->resetParsedAstCache();
		$result = $parser->parseGroups('/(?<year>\d{4})-(?<month>\d{2})/');
		self::assertNotNull($result);

		$year = $result->getCapturingGroups()[1] ?? null;
		self::assertNotNull($year);
		$type = $year->getType();
		self::assertStringContainsString('numeric-string', $type->describe(VerbosityLevel::precise()));
	}

	private function createParser(): RegexGroupParser
	{
		$helper = (new ReflectionClass(RegexExpressionHelper::class))->newInstanceWithoutConstructor();

		return new RegexGroupParser(
			new PhpVersion(PHP_VERSION_ID),
			new PHPRegexReader(new PhpVersion(PHP_VERSION_ID), PcreVersion::fromRuntime(), $helper),
		);
	}

	private function resetParsedAstCache(): void
	{
		// a closure bound to the class reaches the private cache on every PHP: reflection needs
		// setAccessible() before PHP 8.1, which PHP 8.5 deprecates
		Closure::bind(static function (): void {
			self::$parsedShapes = [];
		}, null, RegexGroupParser::class)();
	}

}
