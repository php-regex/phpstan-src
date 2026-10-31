<?php declare(strict_types = 1);

namespace PHPStan\Type\Regex;

use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Php\PcreVersion;
use PHPStan\Php\PhpVersion;
use function in_array;
use function intdiv;
use function rtrim;
use function sprintf;
use function strlen;
use function trim;
use const PHP_VERSION_ID;

/**
 * Parses regex patterns with PHPRegex, for the analysed PHP version and its PCRE2 version.
 */
#[AutowiredService]
final class PHPRegexReader
{

	private ?RegexParser $parser = null;

	public function __construct(
		private PhpVersion $phpVersion,
		private PcreVersion $pcreVersion,
		private RegexExpressionHelper $regexExpressionHelper,
	)
	{
	}

	/**
	 * @param string $rawRegex pattern without delimiters and modifiers
	 *
	 * @throws RegexException
	 */
	public function read(string $rawRegex, string $modifiers, string $delimiter): RegexNode
	{
		return $this->getParser()->parsePattern($rawRegex, $modifiers, $delimiter);
	}

	/**
	 * Returns null when the pattern is invalid for the analysed PCRE2 version,
	 * or when the parser cannot read it.
	 */
	public function readExpression(string $regex): ?RegexNode
	{
		$result = $this->judge($regex);
		if ($result === null) {
			return null;
		}

		return $result->ast;
	}

	/**
	 * Returns the error the analysed PCRE2 version reports for the pattern, or null.
	 */
	public function refusalOfExpression(string $pattern): ?string
	{
		$result = $this->judge($pattern);
		if ($result === null || $result->isValid) {
			return null;
		}

		// the parser hit one of its own limits, the pattern may still be valid
		if ($result->isLimit()) {
			return null;
		}

		if ($result->offset !== null && $result->error !== null) {
			return sprintf('%s at offset %d', rtrim($result->error, '.'), $result->offset);
		}

		return $result->error;
	}

	/**
	 * @return array{body: string, modifiers: string, delimiter: string}
	 */
	public function split(string $regex): array
	{
		return [
			'body' => $this->regexExpressionHelper->removeDelimitersAndModifiers($regex),
			'modifiers' => $this->regexExpressionHelper->getPatternModifiers($regex) ?? '',
			'delimiter' => $this->regexExpressionHelper->getPatternDelimiter($regex) ?? '/',
		];
	}

	/**
	 * PCRE2 version the patterns are parsed for, e.g. "10.44".
	 */
	public function judgedPcreVersion(): string
	{
		return $this->getParser()->target()->pcreVersion;
	}

	/**
	 * The verdict of the analysed PCRE2 version on the pattern, with its tree when
	 * it is valid; null for an empty pattern, or when the parser could not finish.
	 */
	private function judge(string $regex): ?ValidationResult
	{
		// a pattern shorter than two characters has no closing delimiter to read
		if (strlen(trim($regex)) < 2) {
			return null;
		}

		['body' => $rawRegex, 'modifiers' => $modifiers, 'delimiter' => $delimiter] = $this->split($regex);

		try {
			return $this->getParser()->validate($this->delimited($rawRegex, $modifiers, $delimiter));
		} catch (ExceptionInterface) {
			// failure of the parser itself, not of the pattern
			return null;
		}
	}

	private function delimited(string $rawRegex, string $modifiers, string $delimiter): string
	{
		static $pairs = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'];

		return $delimiter . $rawRegex . ($pairs[$delimiter] ?? $delimiter) . $modifiers;
	}

	private function getParser(): RegexParser
	{
		return $this->parser ??= RegexParser::create($this->getParserOptions());
	}

	/**
	 * A PCRE2 version set in the configuration or in composer.json is used as is.
	 * The PCRE2 version of the running PHP is used only when the analysed PHP
	 * is the same minor version; otherwise the parser uses the PCRE2 version
	 * bundled with the analysed PHP version.
	 *
	 * @return array{php_version: int, pcre_version?: string}
	 */
	private function getParserOptions(): array
	{
		$phpVersionId = $this->phpVersion->getVersionId();
		$options = ['php_version' => $phpVersionId];

		$isConfigured = in_array($this->pcreVersion->getSource(), [PcreVersion::SOURCE_CONFIG, PcreVersion::SOURCE_COMPOSER_PLATFORM_PCRE], true);
		$isRuntimeOfSameMinor = $this->pcreVersion->getSource() === PcreVersion::SOURCE_RUNTIME
			&& intdiv($phpVersionId, 100) === intdiv(PHP_VERSION_ID, 100);

		if ($isConfigured || $isRuntimeOfSameMinor) {
			$options['pcre_version'] = $this->pcreVersion->getVersionString();
		}

		return $options;
	}

}
