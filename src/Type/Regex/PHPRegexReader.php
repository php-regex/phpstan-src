<?php declare(strict_types = 1);

namespace PHPStan\Type\Regex;

use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Syntax\Delimiters;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Php\PcreVersion;
use PHPStan\Php\PhpVersion;

/**
 * Reads a pattern body into the PHPRegex AST, judged for the analysed
 * project: the analysed PHP and its bundled engine, narrowed below it when
 * the config or composer.json names an older one — a pin never raises the
 * judged engine above what that PHP can run. A padded \x{ 41 } escape is
 * the letter A on the engines PHP 8.x ships with and the letter x then
 * text on PHP 7.4's, and the trees follow that verdict.
 */
#[AutowiredService]
final class PHPRegexReader
{

	private ?RegexParser $parser = null;

	public function __construct(
		private PhpVersion $phpVersion,
		private PcreVersion $pcreVersion,
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
	 * Whether the engine the judged target runs accepts the pattern: the
	 * parser validates for the target it reads for, so a construct the
	 * running engine takes but the analysed one predates is refused here.
	 */
	public function accepts(string $rawRegex, string $modifiers, string $delimiter): bool
	{
		$this->parser ??= RegexParser::create($this->targetOptions());

		$regex = $delimiter . $rawRegex . Delimiters::closing($delimiter) . $modifiers;

		return $this->parser->validate($regex)->isValid;
	}

	/**
	 * @return array<string, int|string>
	 */
	private function targetOptions(): array
	{
		$phpVersionId = $this->phpVersion->getVersionId();
		$bundled = PcreTarget::bundledWith($phpVersionId);

		$options = ['php_version' => $phpVersionId];
		if (!$this->pcreVersion->atLeast($bundled->pcreVersion)) {
			$options['pcre_version'] = $this->pcreVersion->getVersionString();
		}

		return $options;
	}

}
