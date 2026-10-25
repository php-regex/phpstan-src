<?php declare(strict_types = 1);

namespace PHPStan\Type\Regex;

use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\ValidationErrorCategory;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Php\PcreVersion;
use PHPStan\Php\PhpVersion;
use function in_array;
use function intdiv;
use function rtrim;
use function sprintf;
use function trim;
use const PHP_VERSION_ID;

/**
 * Reads a pattern body into the PHPRegex AST, judged for the analysed
 * project. A named engine — the `pcreVersion` parameter or
 * `config.platform.lib-pcre` — is a statement about that project, honoured
 * in both directions; the running engine speaks for the analysed PHP only
 * when the two share a minor, and stays silent from another. A padded
 * \x{ 41 } escape is the letter A on the engines PHP 8.x ships with and
 * the letter x then text on PHP 7.4's, and the trees follow that verdict.
 */
#[AutowiredService]
final class PHPRegexReader
{

	private ?RegexParser $parser = null;

	public function __construct(
		private PhpVersion $phpVersion,
		private PcreVersion $pcreVersion,
		private RegexExpressionHelper $regexExpressionHelper
	)
	{
	}

	/**
	 * @param string $rawRegex  the pattern body, delimiters and modifiers stripped
	 * @param string $modifiers the pattern modifiers as RegexGroupParser read them
	 * @param string $delimiter the opening delimiter of the pattern as written
	 *
	 * @throws RegexException when the pattern body cannot be read
	 */
	public function read(string $rawRegex, string $modifiers, string $delimiter): RegexNode
	{
		$this->parser ??= RegexParser::create($this->targetOptions());

		return $this->parser->parsePattern($rawRegex, $modifiers, $delimiter);
	}

	/**
	 * Reads a pattern as written — delimiters, modifiers, all — judged for
	 * the analysed project: split where the helper splits it, refused when
	 * the engine its target runs refuses it, and read into the parser's
	 * own tree otherwise. Null when the target takes none of it.
	 */
	public function readExpression(string $regex): ?RegexNode
	{
		$trimmed = trim($regex);
		if ($trimmed === '') {
			return null;
		}

		['body' => $rawRegex, 'modifiers' => $modifiers, 'delimiter' => $delimiter] = $this->split($regex);

		if (!$this->accepts($rawRegex, $modifiers, $delimiter)) {
			return null;
		}

		try {
			return $this->read($rawRegex, $modifiers, $delimiter);
		} catch (ExceptionInterface) {
			// a limit the library hit or a failure inside it is no verdict
			// on the pattern: no shape, never a crash
			return null;
		}
	}

	/**
	 * The target engine's refusal of a pattern as written, or null when it
	 * takes the pattern — the rule's question, asked of the same judge the
	 * shapes read.
	 */
	public function refusalOfExpression(string $pattern): ?string
	{
		$trimmed = trim($pattern);
		if ($trimmed === '') {
			return null;
		}

		['body' => $rawRegex, 'modifiers' => $modifiers, 'delimiter' => $delimiter] = $this->split($pattern);

		return $this->refusal($rawRegex, $modifiers, $delimiter);
	}

	/**
	 * A pattern as written, split where the helper splits it: the body
	 * between the delimiters, the modifiers that follow the closing one,
	 * and the opening delimiter itself.
	 *
	 * @return array{body: string, modifiers: string, delimiter: string}
	 */
	public function split(string $regex): array
	{
		return [
			'body' => $this->regexExpressionHelper->removeDelimitersAndModifiers($regex),
			'modifiers' => $this->regexExpressionHelper->getPatternModifiers($regex) ?? '',
			'delimiter' => trim($regex) === '' ? '/' : trim($regex)[0],
		];
	}

	/**
	 * The engine the patterns read here are judged for: the baseline the
	 * analysed PHP carries, narrowed or widened by what the pin or the
	 * witness says.
	 */
	public function judgedPcreVersion(): string
	{
		$this->parser ??= RegexParser::create($this->targetOptions());

		return $this->parser->target()->pcreVersion;
	}

	/**
	 * Whether the engine the judged target runs accepts the pattern: the
	 * parser validates for the target it reads for, so a construct the
	 * running engine takes but the analysed one predates is refused here.
	 */
	public function accepts(string $rawRegex, string $modifiers, string $delimiter): bool
	{
		return $this->refusal($rawRegex, $modifiers, $delimiter) === null;
	}

	/**
	 * The target engine's of a pattern, in its own words and at
	 * its own offset, or null when it takes the pattern.
	 */
	public function refusal(string $rawRegex, string $modifiers, string $delimiter): ?string
	{
		$this->parser ??= RegexParser::create($this->targetOptions());

		try {
			$result = $this->parser->validate($this->delimited($rawRegex, $modifiers, $delimiter));
		} catch (ExceptionInterface) {
			// the library failed, not the pattern: no verdict
			return null;
		}

		if ($result->isValid) {
			return null;
		}

		// a limit of the library is not a refusal of the pattern: the
		// engine may well take what the library stopped reading
		if ($result->category === ValidationErrorCategory::Limit()) {
			return null;
		}

		if ($result->offset !== null && $result->error !== null) {
			return sprintf('%s at offset %d', rtrim($result->error, '.'), $result->offset);
		}

		return $result->error;
	}

	/**
	 * The pattern as it was written: a bracket delimiter closes with its
	 * pair, any other one closes with itself.
	 */
	private function delimited(string $rawRegex, string $modifiers, string $delimiter): string
	{
		static $pairs = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'];

		return $delimiter . $rawRegex . ($pairs[$delimiter] ?? $delimiter) . $modifiers;
	}

	/**
	 * The parser options that judge the pattern for the analysed project.
	 *
	 * A named engine is a statement about that project — the config or the
	 * composer platform says which engine it runs — and is honoured in both
	 * directions. The running engine is only a witness: it speaks for the
	 * analysed PHP when the two share a minor, because a patch release
	 * carries whatever engine was current when it was cut, and the baseline
	 * map only knows the minor's first. From another minor the witness
	 * stays silent and the baseline judges.
	 *
	 * @return array{php_version: int, pcre_version?: string}
	 */
	private function targetOptions(): array
	{
		$phpVersionId = $this->phpVersion->getVersionId();

		$options = ['php_version' => $phpVersionId];

		$isStatement = in_array($this->pcreVersion->getSource(), [PcreVersion::SOURCE_CONFIG, PcreVersion::SOURCE_COMPOSER_PLATFORM_PCRE], true);
		$speaksForAnalysed = $this->pcreVersion->getSource() === PcreVersion::SOURCE_RUNTIME
			&& intdiv($phpVersionId, 100) === intdiv(PHP_VERSION_ID, 100);

		if ($isStatement || $speaksForAnalysed) {
			$options['pcre_version'] = $this->pcreVersion->getVersionString();
		}

		return $options;
	}

}
