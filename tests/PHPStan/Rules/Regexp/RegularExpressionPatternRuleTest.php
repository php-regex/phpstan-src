<?php declare(strict_types = 1);

namespace PHPStan\Rules\Regexp;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPStan\Type\Regex\PHPRegexReader;
use PHPStan\Type\Regex\RegexExpressionHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use function sprintf;
use const PHP_VERSION_ID;

/**
 * @extends RuleTestCase<RegularExpressionPatternRule>
 */
class RegularExpressionPatternRuleTest extends RuleTestCase
{

	protected function getRule(): Rule
	{
		return new RegularExpressionPatternRule(
			self::getContainer()->getByType(RegexExpressionHelper::class),
			self::getContainer()->getByType(PHPRegexReader::class),
		);
	}

	public function testValidRegexPattern(): void
	{
		$this->analyse(
			[__DIR__ . '/data/valid-regex-pattern.php'],
			[
				[
					'Regex pattern is invalid: Invalid delimiter "n". Delimiters must not be alphanumeric, backslash, or NUL byte. Try #nonok#.',
					6,
				],
				[
					'Regex pattern is invalid: Expected ) at end of input (found eof) at offset 1',
					7,
				],
				[
					'Regex pattern is invalid: Invalid delimiter "n". Delimiters must not be alphanumeric, backslash, or NUL byte. Try #nonok#.',
					11,
				],
				[
					'Regex pattern is invalid: Expected ) at end of input (found eof) at offset 1',
					12,
				],
				[
					'Regex pattern is invalid: Invalid delimiter "n". Delimiters must not be alphanumeric, backslash, or NUL byte. Try #nonok#.',
					16,
				],
				[
					'Regex pattern is invalid: Expected ) at end of input (found eof) at offset 1',
					17,
				],
				[
					'Regex pattern is invalid: Invalid delimiter "n". Delimiters must not be alphanumeric, backslash, or NUL byte. Try #nonok#.',
					21,
				],
				[
					'Regex pattern is invalid: Expected ) at end of input (found eof) at offset 1',
					22,
				],
				[
					'Regex pattern is invalid: Invalid delimiter "n". Delimiters must not be alphanumeric, backslash, or NUL byte. Try #nonok#.',
					26,
				],
				[
					'Regex pattern is invalid: Expected ) at end of input (found eof) at offset 1',
					27,
				],
				[
					'Regex pattern is invalid: Invalid delimiter "n". Delimiters must not be alphanumeric, backslash, or NUL byte. Try #nonok#.',
					29,
				],
				[
					'Regex pattern is invalid: Expected ) at end of input (found eof) at offset 1',
					29,
				],
				[
					'Regex pattern is invalid: Invalid delimiter "n". Delimiters must not be alphanumeric, backslash, or NUL byte. Try #nonok#.',
					32,
				],
				[
					'Regex pattern is invalid: Expected ) at end of input (found eof) at offset 1',
					33,
				],
				[
					'Regex pattern is invalid: Invalid delimiter "n". Delimiters must not be alphanumeric, backslash, or NUL byte. Try #nonok#.',
					35,
				],
				[
					'Regex pattern is invalid: Expected ) at end of input (found eof) at offset 1',
					35,
				],
				[
					'Regex pattern is invalid: Invalid delimiter "n". Delimiters must not be alphanumeric, backslash, or NUL byte. Try #nonok#.',
					38,
				],
				[
					'Regex pattern is invalid: Expected ) at end of input (found eof) at offset 1',
					39,
				],
				[
					'Regex pattern is invalid: Invalid delimiter "n". Delimiters must not be alphanumeric, backslash, or NUL byte. Try #nonok#.',
					41,
				],
				[
					'Regex pattern is invalid: Expected ) at end of input (found eof) at offset 1',
					41,
				],
				[
					'Regex pattern is invalid: Invalid delimiter "n". Delimiters must not be alphanumeric, backslash, or NUL byte. Try #nonok#.',
					43,
				],
				[
					'Regex pattern is invalid: Expected ) at end of input (found eof) at offset 1',
					43,
				],
				[
					'Regex pattern is invalid: Invalid delimiter "n". Delimiters must not be alphanumeric, backslash, or NUL byte. Try #nok(?:.*nok(?:.*)#.',
					57,
				],
				[
					'Regex pattern is invalid: Invalid delimiter "n". Delimiters must not be alphanumeric, backslash, or NUL byte. Try #nok(?:.*nok(?:.*)#.',
					58,
				],
				[
					'Regex pattern is invalid: Expected ) at end of input (found eof) at offset 7',
					59,
				],
				[
					'Regex pattern is invalid: Invalid delimiter "n". Delimiters must not be alphanumeric, backslash, or NUL byte. Try #nok(?:.*)nono#.',
					61,
				],
				[
					'Regex pattern is invalid: Invalid delimiter "n". Delimiters must not be alphanumeric, backslash, or NUL byte. Try #nok(?:.*)nope#.',
					62,
				],
				[
					'Regex pattern is invalid: Expected ) at end of input (found eof) at offset 7',
					63,
				],
			],
		);
	}

	/**
	 * @param list<array{0: string, 1: int, 2?: string|null}> $errors
	 */
	#[DataProvider('dataArrayShapePatterns')]
	public function testArrayShapePatterns(string $file, array $errors): void
	{
		$this->analyse(
			[$file],
			$errors,
		);
	}

	public static function dataArrayShapePatterns(): iterable
	{
		yield [
			__DIR__ . '/../../Analyser/nsrt/preg_match_all_shapes.php',
			[],
		];

		yield [
			__DIR__ . '/../../Analyser/nsrt/preg_match_shapes.php',
			[
				[
					"Regex pattern is invalid: Unknown regex flag(s) found: \"y\", \"z\" at offset 17",
					124,
				],
			],
		];

		if (PHP_VERSION_ID >= 80000) {
			yield [
				__DIR__ . '/../../Analyser/nsrt/preg_match_shapes_php80.php',
				[],
			];
		}

		if (PHP_VERSION_ID >= 80200) {
			yield [
				__DIR__ . '/../../Analyser/nsrt/preg_match_shapes_php82.php',
				[],
			];
		}

		yield [
			__DIR__ . '/../../Analyser/nsrt/preg_replace_callback_shapes.php',
			[],
		];
		yield [
			__DIR__ . '/../../Analyser/nsrt/preg_replace_callback_shapes-php72.php',
			[],
		];
	}

}
