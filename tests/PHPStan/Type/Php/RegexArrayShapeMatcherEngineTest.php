<?php declare(strict_types = 1);

namespace PHPStan\Type\Php;

use PhpParser\Error;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPStan\Analyser\ScopeContext;
use PHPStan\Analyser\ScopeFactory;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\ConstantTypeHelper;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use function array_filter;
use function array_keys;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function crc32;
use function file_get_contents;
use function glob;
use function in_array;
use function ini_get;
use function ini_set;
use function is_string;
use function mb_str_split;
use function mt_rand;
use function mt_srand;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function preg_replace_callback;
use function sprintf;
use function strtolower;
use function strtoupper;
use function var_export;
use const PREG_OFFSET_CAPTURE;
use const PREG_PATTERN_ORDER;
use const PREG_SET_ORDER;
use const PREG_UNMATCHED_AS_NULL;

/**
 * Checks the $matches types against the engine: for every constant pattern of the
 * preg_* fixtures, each array preg_match(), preg_match_all() and preg_replace_callback()
 * write for a set of subjects must be accepted by the inferred type.
 */
class RegexArrayShapeMatcherEngineTest extends PHPStanTestCase
{

	private const MATCH_FLAGS = [
		0,
		PREG_OFFSET_CAPTURE,
		PREG_UNMATCHED_AS_NULL,
		PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL,
	];

	private const MATCH_ALL_FLAGS = [
		PREG_PATTERN_ORDER,
		PREG_PATTERN_ORDER | PREG_OFFSET_CAPTURE,
		PREG_PATTERN_ORDER | PREG_UNMATCHED_AS_NULL,
		PREG_SET_ORDER,
		PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
		PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL,
	];

	private const SUBJECTS = [
		'', '0', '1', '00', '10', 'a', 'A', 'b', 'c', 'x', 'ab', 'aa', 'abc', "a\n", "\n", "a\nb", "\r\n",
		' ', '-1', '1.5', '٣', '١٢', 'K', 'k', 'é', 'É', "\xc3", "\xff", '#', 'foo', 'bar', 'foobar', '_', '0a', 'a0',
	];

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function dataPatterns(): iterable
	{
		// the fixtures are read as the newest PHP, whatever PHP runs the suite; one written for a PHP
		// the parser does not read yet is left out
		$parser = (new ParserFactory())->createForNewestSupportedVersion();
		$finder = new NodeFinder();
		$patterns = [];
		foreach (glob(__DIR__ . '/../../Analyser/nsrt/*.php') ?: [] as $file) {
			$code = file_get_contents($file);
			if ($code === false || preg_match('/\bpreg_(?:match|match_all|replace_callback)\s*\(/', $code) !== 1) {
				continue;
			}

			try {
				$statements = $parser->parse($code) ?? [];
			} catch (Error) {
				continue;
			}

			foreach ($finder->findInstanceOf($statements, FuncCall::class) as $call) {
				if (
					!$call->name instanceof Name
					|| !in_array($call->name->toLowerString(), ['preg_match', 'preg_match_all', 'preg_replace_callback'], true)
				) {
					continue;
				}

				$pattern = $call->getArgs()[0]->value ?? null;
				if (!$pattern instanceof String_ || @preg_match($pattern->value, '') === false) {
					continue;
				}

				$patterns[$pattern->value] = true;
			}
		}

		foreach (array_keys($patterns) as $pattern) {
			yield $pattern => [$pattern];
		}
	}

	#[DataProvider('dataPatterns')]
	public function testEngineMatches(string $pattern): void
	{
		$matcher = self::getContainer()->getByType(RegexArrayShapeMatcher::class);
		$scope = self::getContainer()->getByType(ScopeFactory::class)->create(ScopeContext::create(__FILE__));
		$patternExpr = new String_($pattern);

		$failures = [];
		$jit = ini_get('pcre.jit');
		ini_set('pcre.jit', '0');
		try {
			foreach ($this->subjects($pattern) as $subject) {
				foreach (self::MATCH_FLAGS as $flags) {
					if (preg_match($pattern, $subject, $matches, $flags) === 1) {
						$failures[] = $this->refusal($matcher->matchExpr($patternExpr, new ConstantIntegerType($flags), TrinaryLogic::createYes(), $scope), $matches, 'preg_match', $pattern, $subject, $flags);
					}

					if (preg_match($pattern, $subject, $matches, $flags, 1) === 1) {
						$failures[] = $this->refusal($matcher->matchExpr($patternExpr, new ConstantIntegerType($flags), TrinaryLogic::createYes(), $scope, true), $matches, 'preg_match from offset 1', $pattern, $subject, $flags);
					}

					// a limit the short subjects never reach: -1 is the default, which cannot be passed positionally, and PHP 7.4 reads no named argument
					preg_replace_callback($pattern, function (array $matches) use ($matcher, $patternExpr, $flags, $scope, $pattern, $subject, &$failures): string {
						$failures[] = $this->refusal($matcher->matchExpr($patternExpr, new ConstantIntegerType($flags), TrinaryLogic::createYes(), $scope, true), $matches, 'preg_replace_callback', $pattern, $subject, $flags);

						return '';
					}, $subject, 100, $count, $flags);
				}

				foreach (self::MATCH_ALL_FLAGS as $flags) {
					// false on a match error, e.g. a subject that is not UTF-8 under /u
					$matchCount = preg_match_all($pattern, $subject, $matches, $flags);
					if ($matchCount === false || $matchCount === 0) {
						continue;
					}

					$failures[] = $this->refusal($matcher->matchAllExpr($patternExpr, new ConstantIntegerType($flags), TrinaryLogic::createYes(), $scope), $matches, 'preg_match_all', $pattern, $subject, $flags);
				}
			}
		} finally {
			ini_set('pcre.jit', is_string($jit) ? $jit : '1');
		}

		self::assertSame([], array_values(array_unique(array_filter($failures, static fn (?string $failure): bool => $failure !== null))));
	}

	/**
	 * @param array<mixed> $matches
	 */
	private function refusal(?Type $type, array $matches, string $function, string $pattern, string $subject, int $flags): ?string
	{
		if ($type === null || $type->isSuperTypeOf(ConstantTypeHelper::getTypeFromValue($matches))->yes()) {
			return null;
		}

		return sprintf(
			'%s(%s) with flags %d on %s wrote %s, the inferred type %s does not accept it',
			$function,
			$pattern,
			$flags,
			var_export($subject, true),
			var_export($matches, true),
			$type->describe(VerbosityLevel::precise()),
		);
	}

	/**
	 * @return list<string>
	 */
	private function subjects(string $pattern): array
	{
		$subjects = self::SUBJECTS;

		preg_match_all('/[A-Za-z0-9]+/', $pattern, $literals);
		foreach (array_slice(array_values(array_unique($literals[0])), 0, 8) as $literal) {
			$subjects[] = $literal;
			$subjects[] = $literal . "\n";
			$subjects[] = 'x' . $literal;
			$subjects[] = strtoupper($literal);
			$subjects[] = strtolower($literal);
			$subjects[] = $literal . $literal;
		}

		$alphabet = mb_str_split(preg_replace('/[\\\\()\[\]{}?*+|^$\/#~]/', '', $pattern) ?? '') ?: [];
		$alphabet = array_values(array_unique([...$alphabet, '0', '1', 'a', ' ', "\n"]));
		mt_srand(crc32($pattern));
		for ($i = 0; $i < 30; $i++) {
			$subject = '';
			for ($length = mt_rand(0, 8); $length > 0; $length--) {
				$subject .= $alphabet[mt_rand(0, count($alphabet) - 1)];
			}
			$subjects[] = $subject;
		}

		return array_values(array_unique($subjects));
	}

}
