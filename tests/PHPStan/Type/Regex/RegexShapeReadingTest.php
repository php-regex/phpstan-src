<?php declare(strict_types = 1);

namespace PHPStan\Type\Regex;

use PHPStan\Php\PcreVersion;
use PHPStan\Php\PcreVersionFactory;
use PHPStan\Php\PhpVersion;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\VerbosityLevel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use function json_encode;
use function mb_internal_encoding;
use function sprintf;

/**
 * Pins the walk semantics of RegexGroupParser now that the PHPRegex
 * parser reads every pattern: what the old grammar read must stay put,
 * what it refused or read wrongly (backreferences, verbs, callouts,
 * \Q..\E, modern conditions, duplicate names, freespacing comments)
 * keeps its verdict pinned here — every multi-byte literal split as the
 * reader splits it, whatever mbstring's default encoding is.
 */
final class RegexShapeReadingTest extends TestCase
{

	public function testMultiByteLiteralsSplitAsUtf8WhateverTheDefaultEncoding(): void
	{
		$defaultEncoding = mb_internal_encoding();
		// under a single-byte internal encoding a two-byte literal splits in
		// two, doubling the meaningful tokens — the shape reads the pattern
		// as UTF-8 whatever the default encoding and keeps one character
		$helper = (new ReflectionClass(RegexExpressionHelper::class))->newInstanceWithoutConstructor();
		mb_internal_encoding('ISO-8859-1');

		try {
			$parser = new RegexGroupParser(
				new PhpVersion(PHP_VERSION_ID),
				new PHPRegexReader(new PhpVersion(PHP_VERSION_ID), PcreVersion::fromRuntime(), $helper),
			);

			$this->resetParsedAstCache();
			$result = $parser->parseGroups('/é/');
			self::assertNotNull($result);
			self::assertSame("'é'", $result->getSubjectBaseType()->describe(VerbosityLevel::precise()), 'one character is one token, not two');
		} finally {
			mb_internal_encoding($defaultEncoding);
		}
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
		$property->setValue(null, []);
	}

	/**
	 * Pattern bodies the old grammar read; the walk results they produced
	 * are covered by the resolver suite's fixtures.
	 *
	 * @return list<string>
	 */
	private function oldGrammarReadableBodies(): array
	{
		return [
			'a',
			'ab',
			'a\.b',
			'a|b',
			'a|',
			'|a',
			'a||b',
			'(a)',
			'(a)(b)',
			'((a))',
			'(?<name>a)',
			'(?:a)',
			'(?i:a)',
			'(?i)',
			'(?n)',
			'(?|a|(b))',
			'(?>a+)',
			'()',
			'a?',
			'a??',
			'a?+',
			'a*',
			'a*?',
			'a*+',
			'a+',
			'a+?',
			'a++',
			'a{2}',
			'a{2,}',
			'a{2,4}',
			'a{2,4}?',
			'a{2,4}+',
			'(ab)+',
			'[ab]+',
			'[abc]',
			'[^abc]',
			'[a-z]',
			'[-a]',
			'[a-]',
			'[]]',
			'[^]]',
			'[a\-z]',
			'[[:digit:]]',
			'[[:^alpha:]]',
			'[a-c\dx]',
			'\d',
			'\D',
			'\w',
			'\s',
			'\h',
			'\v',
			'\R',
			'\N',
			'\p{L}',
			'\P{L}',
			'\p{Greek}',
			'\x41',
			'\101',
			'\n',
			'\t',
			'\cA',
			'^a$',
			'\b',
			'\B',
			'\A',
			'\z',
			'\Z',
			'\G',
			'\K',
			'(?=a)',
			'(?!a)',
			'(?<=a)',
			'(?<!a)',
			'a(?=b)c',
			// (?(1)a|b) and friends stay out: a condition naming a group the
			// pattern never defines is invalid for the engine itself, so the
			// pre-check refuses it before the parser is ever asked, and \x{1F600}
			// without /u likewise
			'(?#comment)',
			'(*:markname)',
		];
	}

	public function testTheGrammarReadablePatternsStillWalk(): void
	{
		$parser = $this->createParser();
		$missing = [];

		foreach ($this->oldGrammarReadableBodies() as $body) {
			$this->resetParsedAstCache();
			$result = $parser->parseGroups('/' . $body . '/');
			if ($result === null) {
				$missing[] = $body;
			}
		}

		self::assertSame([], $missing, 'Patterns the old grammar read that the parser now refuses: ' . implode(', ', $missing));
	}

	/**
	 * Pattern bodies the old grammar could not read at all, or read with a
	 * phantom literal: the PHPRegex backend must return a walk result anyway.
	 * Bodies reference groups that exist, so the engine pre-check passes.
	 *
	 * @return list<string>
	 */
	private function hoaBlindBodies(): array
	{
		return [
			'(a)\1',
			'(a)(\1)',
			'(a)\g{1}',
			'(a)\g1',
			'(a)\g{-1}',
			'(?<n>a)\k<n>',
			"(?<n>a)\k'n'",
			'(?<n>a)(?P=n)',
			'(?<n>a)\g{n}',
			'(?<name>a)\g<name>',
			"(?<name>a)\g'name'",
			'(?+1)(a)',
			'(a)(?1)',
			'\Qa.b\E',
			'a\Qb.c\E',
			'(?(R)a|b)',
			'(?(DEFINE)a)',
			'(?(VERSION>=10.4)a|b)',
			'(?(?=a)a|b)',
			'(\x31)',
			'a{2}?',
			'(a)(*PRUNE)b',
			'a(*SKIP)b',
			'(a)(*FAIL)b',
			'(?:(*ACCEPT)a)',
			'(a)(?C1)b',
		];
	}

	public function testTheReaderParsesWhatTheGrammarCouldNot(): void
	{
		$parser = $this->createParser();
		$missing = [];

		$this->resetParsedAstCache();

		foreach ($this->hoaBlindBodies() as $body) {
			$result = $parser->parseGroups('/' . $body . '/');
			if ($result === null) {
				$missing[] = $body;
			}
		}

		self::assertSame([], $missing, 'Patterns the PHPRegex backend also refused: ' . implode(', ', $missing));
	}

	public function testBackreferenceIsNotTheLiteralOne(): void
	{
		$parser = $this->createParser();

		// PCRE truth: preg_match('/(a)(\1)/', 'aa') fills group 2 with 'a'.
		// The old grammar read \1 as the literal "1", so group 2 becomes the
		// constant string '1' today.
		$this->resetParsedAstCache();
		$result = $parser->parseGroups('/(a)(\1)/');

		self::assertNotNull($result);
		$groups = array_values($result->getCapturingGroups());
		self::assertCount(2, $groups);
		self::assertNotInstanceOf(ConstantStringType::class, $groups[1]->getType(), 'group 2 must not be a constant string');
		self::assertTrue($groups[1]->getType()->isSuperTypeOf(new ConstantStringType('a'))->yes());
	}

	public function testNoAutoCaptureOptionDropsTheGroups(): void
	{
		$parser = $this->createParser();

		// PCRE truth: (?n) turns capturing groups off, the match array holds
		// only the full match. The old grammar could not see the option, so a
		// phantom group 1 survives in the shapes inferred today.
		$matched = preg_match('/(?n)(\d+)/', 'a5', $matches);
		self::assertSame(1, $matched);
		self::assertSame(['5'], $matches, 'the engine itself must not capture group 1');

		$this->resetParsedAstCache();
		$result = $parser->parseGroups('/(?n)(\d+)/');

		self::assertNotNull($result);
		self::assertSame([], $result->getCapturingGroups(), 'the walk must not report a capturing group');
	}

	/**
	 * @throws \PHPRegex\Parser\Exception\RegexException
	 */
	public function testTheReaderHandsBackTheParserOwnTree(): void
	{
		// no translation layer stands between the walkers and the parser's
		// AST: what read() returns is what the parser built, walked as is
		$helper = (new ReflectionClass(RegexExpressionHelper::class))->newInstanceWithoutConstructor();
		$reader = new PHPRegexReader(new PhpVersion(80400), PcreVersion::fromString('10.44'), $helper);

		$tree = $reader->read('(a)', '', '/');
		self::assertSame('/', $tree->delimiter);
		$body = $tree->pattern;
		self::assertInstanceOf(\PHPRegex\Parser\Node\GroupNode::class, $body);
		self::assertSame(\PHPRegex\Parser\Node\GroupType::Capturing(), $body->type);
		self::assertInstanceOf(\PHPRegex\Parser\Node\LiteralNode::class, $body->child);
		self::assertSame('a', $body->child->value);

		$tree = $reader->read('a|b', '', '/');
		self::assertInstanceOf(\PHPRegex\Parser\Node\AlternationNode::class, $tree->pattern);
		self::assertCount(2, $tree->pattern->alternatives);

		$tree = $reader->read('a{2,4}', '', '/');
		self::assertInstanceOf(\PHPRegex\Parser\Node\QuantifierNode::class, $tree->pattern);
		$bounds = \PHPRegex\Parser\Node\QuantifierBounds::parse($tree->pattern->quantifier);
		self::assertNotNull($bounds);
		self::assertSame(2, $bounds->min);
		self::assertSame(4, $bounds->max);
	}

	public function testDuplicateGroupNamesKeepTheirShapesInlineAndTrailing(): void
	{
		$parser = $this->createParser();

		// The two spellings of the duplicate-name modifier read the same
		// shapes: inline (?J) and trailing /J, two groups of one name across
		// alternation branches.
		$this->resetParsedAstCache();

		$result = $parser->parseGroups('/(?J)(?<Foo>[a-z]+)|(?<Foo>[0-9]+)/');
		self::assertNotNull($result);
		$groups = array_values($result->getCapturingGroups());
		self::assertCount(2, $groups);
		self::assertSame('Foo', $groups[0]->getName());
		self::assertSame('Foo', $groups[1]->getName());

		$this->resetParsedAstCache();
		$trailing = $parser->parseGroups('/(?<Foo>[a-z]+)|(?<Foo>[0-9]+)/J');
		self::assertNotNull($trailing, 'the trailing spelling reads as the inline one does');
		$groups = array_values($trailing->getCapturingGroups());
		self::assertCount(2, $groups);
		self::assertSame('Foo', $groups[0]->getName());
		self::assertSame('Foo', $groups[1]->getName());
	}

	/**
	 * @throws \PHPRegex\Parser\Exception\RegexException
	 */
	public function testTheAnalysedPhpVersionShapesTheEscape(): void
	{
		// \x{ 41 } is a padded hex escape: engines from 10.43 read it as the
		// letter A, older ones PHP 7.4 ships with refuse it and read the
		// letter x then plain text. The pattern compiles on the running
		// engine either way, so the shapes must follow the PHP the project
		// analyses for — the grammar the pipeline replaced could not tell
		// the two apart at all.
		$helper = (new ReflectionClass(RegexExpressionHelper::class))->newInstanceWithoutConstructor();
		// the engines each judged PHP bundles, pinned: the test says the same
		// thing on every machine, whatever engine the runner links
		$php74 = new RegexGroupParser(new PhpVersion(70400), new PHPRegexReader(new PhpVersion(70400), PcreVersion::fromString('10.33'), $helper));
		$php84 = new RegexGroupParser(new PhpVersion(80400), new PHPRegexReader(new PhpVersion(80400), PcreVersion::fromString('10.44'), $helper));

		$this->resetParsedAstCache();
		$result74 = $php74->parseGroups('/(a\x{ 41 })/');
		$this->resetParsedAstCache();
		$result84 = $php84->parseGroups('/(a\x{ 41 })/');

		self::assertNotNull($result74);
		self::assertNotNull($result84);

		$group74 = array_values($result74->getCapturingGroups())[0]->getType();
		$group84 = array_values($result84->getCapturingGroups())[0]->getType();

		self::assertInstanceOf(ConstantStringType::class, $group74, '7.4 reads the letter x then text');
		self::assertSame('ax{ 41 }', $group74->getValue());
		self::assertInstanceOf(ConstantStringType::class, $group84, '8.x reads the padded escape');
		self::assertSame('aA', $group84->getValue());

		// the reader is a service carrying its own target: no statics, no
		// environment, one instance per judged PHP, and it hands back the
		// parser's own tree, not a translation of it
		$reader74 = new PHPRegexReader(new PhpVersion(70400), PcreVersion::fromString('10.33'), $helper);
		$reader84 = new PHPRegexReader(new PhpVersion(80400), PcreVersion::fromString('10.44'), $helper);

		$tree74 = $reader74->read('a\x{ 41 }', '', '/')->pattern;
		$tree84 = $reader84->read('a\x{ 41 }', '', '/')->pattern;

		$fingerprint = static function (\PHPRegex\Parser\Node\NodeInterface $n) use (&$fingerprint): string {
			$children = '';
			foreach ($n->getChildren() as $child) {
				$children .= $fingerprint($child);
			}

			$name = (new ReflectionClass($n))->getShortName();
			$detail = '';
			foreach (['value', 'quantifier', 'type', 'isNegated'] as $prop) {
				$propValue = property_exists($n, $prop) ? $n->$prop : null;
				if ($propValue !== null && (is_string($propValue) || is_int($propValue) || is_bool($propValue))) {
					$detail .= '|' . $propValue;
				} elseif ($propValue !== null) {
					$detail .= '|<' . (new ReflectionClass($propValue))->getShortName() . '>';
				}
			}

			return sprintf('(%s%s)', $name, $detail) . $children;
		};

		self::assertSame($fingerprint($tree74), $fingerprint($reader74->read('a\x{ 41 }', '', '/')->pattern), 'the same target reads the same tree twice');
		self::assertNotSame($fingerprint($tree74), $fingerprint($tree84), 'the two targets build two trees');
	}

	public function testPcreVersionMirrorsPhpVersion(): void
	{
		// the same architecture PhpVersion has: sources with labels, a
		// factory fed by config and composer.json, runtime as the default
		$runtime = (new PcreVersionFactory(null, null))->create();
		self::assertSame(PcreVersion::SOURCE_RUNTIME, $runtime->getSource());
		self::assertSame('runtime', $runtime->getSourceLabel());
		self::assertMatchesRegularExpression('/^10\.\d\d$/', $runtime->getVersionString());

		// the running engine in one call, its release date trimmed away
		self::assertSame(
			$runtime->getVersionId(),
			PcreVersion::fromRuntime()->getVersionId(),
			'fromRuntime spells what the factory defaults to',
		);
		self::assertSame(PcreVersion::SOURCE_RUNTIME, PcreVersion::fromRuntime()->getSource());

		$config = (new PcreVersionFactory('10.42', null))->create();
		self::assertSame(PcreVersion::SOURCE_CONFIG, $config->getSource());
		self::assertSame('config', $config->getSourceLabel());
		self::assertSame('10.42', $config->getVersionString());
		self::assertSame(1042, $config->getVersionId());
		self::assertFalse($config->atLeast('10.43'), 'below the release padded hex escapes need');
		self::assertTrue($config->atLeast('10.42'), 'at itself');
		self::assertFalse($config->atLeast('10.44'), 'below the release long group names need');

		$composer = (new PcreVersionFactory(null, '10.37'))->create();
		self::assertSame(PcreVersion::SOURCE_COMPOSER_PLATFORM_PCRE, $composer->getSource());
		self::assertSame('config.platform.lib-pcre in composer.json', $composer->getSourceLabel());
		self::assertSame('10.37', $composer->getVersionString());

		// the spelling PCRE2 writes; a runtime the factory cannot read falls
		// back to the engine the running PHP bundles, never takes the boot
		// down
		// two digits on each side, whatever the series: 11 is not out yet,
		// 12 will read the same way 10 does
		self::assertSame('11.00', PcreVersion::fromString('11.00')->getVersionString());
		self::assertSame('12.00', PcreVersion::fromString('12.00')->getVersionString());

		try {
			PcreVersion::fromString('banana');
			self::fail('an engine release must be spelled like one');
		} catch (\InvalidArgumentException) {
		}
		try {
			PcreVersion::fromString('11.5');
			self::fail('a minor is two digits, as PCRE2 writes it');
		} catch (\InvalidArgumentException) {
		}
		try {
			PcreVersion::fromString('9.42');
			self::fail('a major is two digits, as PCRE2 writes it since the 10 series');
		} catch (\InvalidArgumentException) {
		}
	}

	/**
	 * @throws \PHPRegex\Parser\Exception\RegexException
	 */
	public function testTheJudgedEngineFollowsThePinThenTheWitness(): void
	{
		// a named engine is a statement about the analysed project, honoured
		// in both directions; the running engine is only a witness, and it
		// speaks for the analysed PHP when the two share a minor — a patch
		// release carries whatever engine was current, and the baseline map
		// only knows the minor's first
		$helper = (new ReflectionClass(RegexExpressionHelper::class))->newInstanceWithoutConstructor();
		$targetOf = static function (PHPRegexReader $reader): string {
			$reader->read('a', '', '/');

			$property = new ReflectionProperty(PHPRegexReader::class, 'parser');
			$property->setAccessible(true);

			/** @var \PHPRegex\Parser\RegexParser $parser */
			$parser = $property->getValue($reader);

			return $parser->target()->pcreVersion;
		};

		$minor = intdiv(PHP_VERSION_ID, 100) * 100;
		$runtime = PcreVersion::fromRuntime();
		$witness = PcreVersion::fromString($runtime->getVersionString(), PcreVersion::SOURCE_RUNTIME);

		self::assertSame('10.49', $targetOf(new PHPRegexReader(new PhpVersion(80400), PcreVersion::fromString('10.49', PcreVersion::SOURCE_CONFIG), $helper)), 'a pin is a statement, honoured above the baseline');
		self::assertSame('10.42', $targetOf(new PHPRegexReader(new PhpVersion(80400), PcreVersion::fromString('10.42', PcreVersion::SOURCE_CONFIG), $helper)), 'a pin below the baseline narrows');
		self::assertSame($witness->getVersionString(), $targetOf(new PHPRegexReader(new PhpVersion($minor), $witness, $helper)), 'the running engine speaks for its own minor');
		self::assertSame(
			\PHPRegex\Parser\PcreTarget::bundledWith(70400)->pcreVersion,
			$targetOf(new PHPRegexReader(new PhpVersion(70400), $witness, $helper)),
			'a witness from another minor stays silent, and the baseline judges',
		);
		self::assertSame('10.49', PcreVersion::fromString('10.49-RC1')->getVersionString(), 'a release candidate reads as its release');
	}

	public function testDefineGroupsAreCountedOnceAndNeverMatch(): void
	{
		$parser = $this->createParser();

		// the engine never fills a group a (?(DEFINE)...) block: it is
		// counted once — the branch it sits in is not walked as a branch —
		// and carries no type of its own
		$this->resetParsedAstCache();
		$result = $parser->parseGroups('/(?(DEFINE)(?<a>x))b/');

		self::assertNotNull($result);
		$groups = array_values($result->getCapturingGroups());
		self::assertCount(1, $groups, 'the defined group is counted once');
		self::assertSame('a', $groups[0]->getName());
		self::assertTrue($groups[0]->isOptional(), 'the engine never fills it');
	}

	public function testADefinedGroupHoldsOnlyTheEmptyString(): void
	{
		$parser = $this->createParser();

		// preg_match('/(?(DEFINE)(?<foo>abc))(?<bar>x)/', 'x', $m) fills
		// bar and leaves foo at '' — the engine never runs what DEFINE holds
		$this->resetParsedAstCache();
		$result = $parser->parseGroups('/(?(DEFINE)(?<foo>abc))(?<bar>x)/');

		self::assertNotNull($result);
		$foo = $result->getCapturingGroups()[1] ?? null;
		self::assertNotNull($foo);
		self::assertSame("''", $foo->getType()->describe(VerbosityLevel::precise()), 'a defined group stays empty');
	}

	public function testConditionalBranchesAreExclusive(): void
	{
		$parser = $this->createParser();

		// preg_match('/(a)(?(1)b|(c))/', 'ab', $m) fills group 1 and never
		// group 2 — the branches run one at a time, as an alternation
		$this->resetParsedAstCache();
		$result = $parser->parseGroups('/(a)(?(1)b|(c))/');

		self::assertNotNull($result);
		$groups = $result->getCapturingGroups();
		self::assertSame("'a'", $groups[1]->getType()->describe(VerbosityLevel::precise()));
		self::assertFalse($groups[1]->isOptional());
		self::assertSame("'c'", $groups[2]->getType()->describe(VerbosityLevel::precise()));
		self::assertTrue($groups[2]->isOptional(), 'the else branch runs alone, or not at all');
	}

	public function testALookaroundConditionCountsItsGroups(): void
	{
		$parser = $this->createParser();

		// the engine numbers the groups a lookaround condition holds before
		// the branches' — preg_match numbers (a) as 1 and (c) as 2, whatever
		// runs
		$this->resetParsedAstCache();
		$result = $parser->parseGroups('/(?(?=(a))b|(c))/');

		self::assertNotNull($result);
		$groups = $result->getCapturingGroups();
		self::assertCount(2, $groups, 'the condition holds group 1');
		self::assertSame("'a'", $groups[1]->getType()->describe(VerbosityLevel::precise()));
		self::assertSame("'c'", $groups[2]->getType()->describe(VerbosityLevel::precise()));
	}

	public function testTheTrailingDuplicateNameModifierReads(): void
	{
		$parser = $this->createParser();

		// PCRE truth: the trailing J allows duplicate names exactly as the
		// inline (?J) spelling does — preg_match('/(?<x>a)(?<x>b)/J', 'ab')
		// fills both groups and the name with the branch that ran
		self::assertSame(1, preg_match('/(?<x>a)(?<x>b)/J', 'ab', $m));
		self::assertSame('{"0":"ab","x":"b","1":"a","2":"b"}', json_encode($m), 'the engine fills both groups and the name with the branch that ran');

		$this->resetParsedAstCache();
		$result = $parser->parseGroups('/(?<x>a)(?<x>b)/J');

		self::assertNotNull($result, 'the trailing J reads as the inline spelling does');
		self::assertCount(2, $result->getCapturingGroups());
		self::assertSame('x', $result->getCapturingGroups()[1]->getName());
		self::assertSame('x', $result->getCapturingGroups()[2]->getName());
	}

	public function testTheMatchAndTheSubjectAreTwoChannels(): void
	{
		$parser = $this->createParser();

		// $matches[0] is the match the engine wrote, constants included;
		// the subject is what the pattern was run against — a different
		// quantity: it contains the match, so its accessories carry over by
		// containment, but even fully anchored $ admits one final newline
		// ("a\n" matches /^(a|b|c)$/), so the subject narrows to accessories
		// alone, never to constants
		$this->resetParsedAstCache();
		$result = $parser->parseGroups('/^(a|b|c)$/');
		self::assertNotNull($result);
		self::assertSame("'a'|'b'|'c'", $result->getSubjectBaseType()->describe(VerbosityLevel::precise()), 'the match channel keeps the constants');
		$narrowType = $result->getSubjectNarrowType();
		self::assertNotNull($narrowType, 'an anchored pattern narrows its subject');
		self::assertSame('non-falsy-string', $narrowType->describe(VerbosityLevel::precise()), 'the subject channel narrows to accessories alone');

		$this->resetParsedAstCache();
		$result = $parser->parseGroups('/foo/');
		self::assertNotNull($result);
		self::assertSame("'foo'", $result->getSubjectBaseType()->describe(VerbosityLevel::precise()), 'the match channel keeps the constant');
		$narrowType = $result->getSubjectNarrowType();
		self::assertNotNull($narrowType);
		self::assertSame('non-falsy-string', $narrowType->describe(VerbosityLevel::precise()), 'a subject that contains a non-falsy match is non-falsy itself');

		$this->resetParsedAstCache();
		$result = $parser->parseGroups('/^$/');
		self::assertNotNull($result);
		self::assertSame("''", $result->getSubjectBaseType()->describe(VerbosityLevel::precise()), 'the match channel keeps the empty constant');
		$narrowType = $result->getSubjectNarrowType();
		self::assertNotNull($narrowType);
		self::assertSame('string', $narrowType->describe(VerbosityLevel::precise()), 'the subject may be the empty string or the final newline');
	}

	public function testTargetInvalidPatternsGetNoShape(): void
	{
		// the pre-check judges for the analysed PHP, not the running one: a
		// padded \x{ 41 } compiles on the runner's 10.43+ engine but the PHP
		// an 8.2 project runs refuses it, so the shape is null and the
		// invalid-pattern rule keeps the running engine as its second
		// witness; the target's word comes first
		$helper = (new ReflectionClass(RegexExpressionHelper::class))->newInstanceWithoutConstructor();
		$php82 = new RegexGroupParser(new PhpVersion(80200), new PHPRegexReader(new PhpVersion(80200), PcreVersion::fromString('10.40'), $helper));
		$php84 = new RegexGroupParser(new PhpVersion(80400), new PHPRegexReader(new PhpVersion(80400), PcreVersion::fromString('10.44'), $helper));

		$this->resetParsedAstCache();
		self::assertNull($php82->parseGroups('/(a\x{ 41 })/'), 'the engine PHP 8.2 bundles refuses the padded escape');

		$this->resetParsedAstCache();
		$result = $php84->parseGroups('/(a\x{ 41 })/');
		self::assertNotNull($result, 'the engine PHP 8.4 bundles reads it');
		$group84 = array_values($result->getCapturingGroups())[0]->getType();
		self::assertInstanceOf(ConstantStringType::class, $group84, 'the shape knows what it reads');
		self::assertSame('aA', $group84->getValue());
	}

	public function testEngineRefusedPatternsGetNoShapeAndBracketDelimitersWalk(): void
	{
		$parser = $this->createParser();

		// the engine itself refuses container verbs in every spelling PHP
		// exposes, so the pre-check nulls the read before a group inside a
		// (*SR:...) or (*SCRIPT_RUN:...) could ever reach the walk — the day
		// an engine takes them, its own parser will carry the body as nodes
		$this->resetParsedAstCache();
		self::assertNull($parser->parseGroups('/(*SR:(?<x>a))b/'));
		$this->resetParsedAstCache();
		self::assertNull($parser->parseGroups('/(*SCRIPT_RUN:(?<x>a))b/'));

		// the delimiter the pattern was written with, brackets included,
		// travels to the reader whole
		$this->resetParsedAstCache();
		$result = $parser->parseGroups('{(a)}');
		self::assertNotNull($result);
		$groups = array_values($result->getCapturingGroups());
		self::assertCount(1, $groups);
		self::assertInstanceOf(ConstantStringType::class, $groups[0]->getType());
		self::assertSame('a', $groups[0]->getType()->getValue());
	}

	public function testXModeCommentsAreReadByTheParserNotPreStripped(): void
	{
		$parser = $this->createParser();

		// PCRE truth: under /x the # only starts a comment outside \Q..\E and
		// classes. The pre-strip that runs before the grammar is blind to
		// both; in front of a mode-aware parser it mangles the pattern.
		$matched = preg_match('/(a\Q#b\Ec)/x', 'a#bc', $matches);
		self::assertSame(1, $matched);
		self::assertSame('["a#bc","a#bc"]', json_encode($matches), 'the engine reads the quoted hash as text');

		$this->resetParsedAstCache();
		$result = $parser->parseGroups('/(a\Q#b\Ec)/x');

		self::assertNotNull($result, 'the pattern must parse whole, comments included');
		$groups = array_values($result->getCapturingGroups());
		self::assertCount(1, $groups);
		$type = $groups[0]->getType();
		self::assertInstanceOf(ConstantStringType::class, $type);
		self::assertSame('a#bc', $type->getValue());
	}

}
