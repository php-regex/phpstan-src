<?php declare(strict_types = 1);

namespace PHPStan\Rules\Regexp;

use Nette\Utils\RegexpException;
use Nette\Utils\Strings;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPStan\Type\Regex\PHPRegexReader;
use PHPStan\Type\Regex\RegexExpressionHelper;
use function file_get_contents;
use function sprintf;

/**
 * @extends RuleTestCase<RegularExpressionPatternRule>
 */
class RegularExpressionPatternRuleAnalysedPhpTest extends RuleTestCase
{

	private bool $checkRegexPatternForAnalysedPhp = true;

	protected function getRule(): Rule
	{
		return new RegularExpressionPatternRule(
			self::getContainer()->getByType(RegexExpressionHelper::class),
			self::getContainer()->getByType(PHPRegexReader::class),
			$this->checkRegexPatternForAnalysedPhp,
		);
	}

	public function testPatternInvalidForAnalysedPhp(): void
	{
		// spaces inside \x{} compile on the PCRE2 bundled with PHP 8.4, not on the one PHP 8.2 bundles
		$this->analyse([__DIR__ . '/data/regex-pattern-analysed-php.php'], self::runningEngineErrors() ?? [
			[
				'Regex pattern is invalid: Spaces inside \x{} need PCRE2 10.43, which PHP bundles from 8.4 at offset 5',
				6,
			],
		]);
	}

	public function testPatternInvalidForAnalysedPhpWithoutToggle(): void
	{
		$this->checkRegexPatternForAnalysedPhp = false;
		$this->analyse([__DIR__ . '/data/regex-pattern-analysed-php.php'], self::runningEngineErrors() ?? []);
	}

	/**
	 * The errors the rule reports before reading the pattern for the analysed PHP: the running
	 * engine's own refusal, on a PCRE2 before 10.43 (the system's on some CI images); null where
	 * the engine compiles the pattern. The pattern is read from the fixture, so that the engine
	 * running the test decides, not the analysis of this file.
	 *
	 * @return list<array{string, int}>|null
	 */
	private static function runningEngineErrors(): ?array
	{
		$fixture = (string) file_get_contents(__DIR__ . '/data/regex-pattern-analysed-php.php');
		$pattern = Strings::match($fixture, "~preg_match\\('([^']+)'~")[1] ?? '';

		try {
			Strings::match('', $pattern);
		} catch (RegexpException $e) {
			return [[sprintf('Regex pattern is invalid: %s', $e->getMessage()), 6]];
		}

		return null;
	}

	public static function getAdditionalConfigFiles(): array
	{
		return [
			__DIR__ . '/data/analysed-php-82.neon',
		];
	}

}
