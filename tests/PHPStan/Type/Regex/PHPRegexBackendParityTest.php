<?php declare(strict_types = 1);

namespace PHPStan\Type\Regex;

use PHPStan\Php\PcreVersion;
use PHPStan\Php\PhpVersion;
use PHPStan\Type\VerbosityLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use function array_keys;
use function ctype_digit;
use function is_int;
use function json_encode;
use function preg_match;
use function str_contains;
use const PHP_VERSION_ID;
use const PREG_UNMATCHED_AS_NULL;

/**
 * The word of the running engine is the oracle: every pattern the reading
 * pipeline must handle — backreferences, verbs, \Q..\E, duplicate names,
 * extended mode, version-shaped escapes, conditionals, DEFINE, subroutine
 * calls — is matched for real, and what the engine writes into $matches is
 * checked against the shape the parser computed: the group count, the
 * values, the digits, the truth of what a group holds.
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
		yield 'version-shaped escape' => ['pattern' => '/(a\x{ 41 })/', 'subject' => 'aA'];
		yield 'digit runs' => ['pattern' => '/(?<year>\d{4})-(?<month>\d{2})/', 'subject' => '2026-10'];
		yield 'conditional' => ['pattern' => '/(a)(?(1)b|(c))/', 'subject' => 'ab'];
		yield 'define' => ['pattern' => '/(?(DEFINE)(?<d>abc))(?<r>x)/', 'subject' => 'x'];
		yield 'subroutine call' => ['pattern' => '/(a)(?1)/', 'subject' => 'aa'];
	}

	#[Test]
	#[DataProvider('providePatterns')]
	public function testTheEngineWritesWhatTheShapeReads(string $pattern, string $subject): void
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

	#[Test]
	public function testAConditionalFillsOnlyOneBranch(): void
	{
		// preg_match('/(a)(?(1)b|(c))/', 'ab', $m) === ['ab', 'a']: the else
		// group is numbered, optional, and never set beside a set condition
		self::assertSame(1, preg_match('/(a)(?(1)b|(c))/', 'ab', $m));
		self::assertSame(['ab', 'a'], $m);

		$parser = $this->createParser();
		$this->resetParsedAstCache();
		$result = $parser->parseGroups('/(a)(?(1)b|(c))/');
		self::assertNotNull($result);

		$else = $result->getCapturingGroups()[2] ?? null;
		self::assertNotNull($else);
		self::assertTrue($else->isOptional(), 'the else branch runs alone, or not at all');
	}

	#[Test]
	public function testADefinedGroupStaysEmpty(): void
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

	#[Test]
	public function testDigitGroupsReadTheirLengths(): void
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
		$property = new ReflectionProperty(RegexGroupParser::class, 'parsedShapes');
		$property->setAccessible(true);
		$property->setValue(null, []);
	}

}
