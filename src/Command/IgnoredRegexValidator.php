<?php declare(strict_types = 1);

namespace PHPStan\Command;

use Nette\Utils\Strings;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\SequenceNode;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\PhpDocParser\Parser\ParserException;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Regex\PHPRegexReader;
use PHPStan\Type\VerbosityLevel;
use function count;
use function strrpos;
use function substr;

final class IgnoredRegexValidator
{

	public function __construct(
		private TypeStringResolver $typeStringResolver,
		private PHPRegexReader $reader,
	)
	{
	}

	public function validate(string $regex): IgnoredRegexValidatorResult
	{
		['body' => $regex, 'modifiers' => $modifiers, 'delimiter' => $delimiter] = $this->reader->split($regex);

		try {
			$root = $this->reader->read($regex, $modifiers, $delimiter);
		} catch (\PHPRegex\Parser\Exception\ExceptionInterface) {
			return new IgnoredRegexValidatorResult(ignoredTypes: [], anchorsInTheMiddle: false, allErrorsIgnored: false);
		}

		if (Strings::match($regex, '~(?<!\\\\)(?:\\\\\\\\)*\|\|~') !== null) {
			return new IgnoredRegexValidatorResult(ignoredTypes: [], anchorsInTheMiddle: false, allErrorsIgnored: true, wrongSequence: '||', escapedWrongSequence: '\|\|');
		}
		if (Strings::match($regex, '~(?<!\\\\)(?:\\\\\\\\)*\(\)~') !== null) {
			return new IgnoredRegexValidatorResult(ignoredTypes: [], anchorsInTheMiddle: false, allErrorsIgnored: true, wrongSequence: '()', escapedWrongSequence: '\(\)');
		}

		return new IgnoredRegexValidatorResult(
			ignoredTypes: $this->getIgnoredTypes($root->pattern, $root->source ?? $regex),
			anchorsInTheMiddle: $this->hasAnchorsInTheMiddle($root->pattern),
			allErrorsIgnored: false,
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function getIgnoredTypes(NodeInterface $ast, string $source): array
	{
		if (!$ast instanceof AlternationNode) {
			return [];
		}

		$types = [];
		foreach ($ast->alternatives as $alternative) {
			$text = $this->getText($alternative, $source);
			if ($text === null) {
				continue;
			}

			$matches = Strings::match($text, '#^([a-zA-Z0-9]+)[,]?\s*#');
			if ($matches === null) {
				continue;
			}

			try {
				$type = $this->typeStringResolver->resolve($matches[1], null);
			} catch (ParserException) {
				continue;
			}

			if ($type->isObject()->yes()) {
				continue;
			}

			$typeDescription = $type->describe(VerbosityLevel::typeOnly());

			if ($typeDescription !== $matches[1]) {
				continue;
			}

			$types[$typeDescription] = $text;
		}

		return $types;
	}

	/**
	 * The text of a branch as it was written, escapes included: the tree
	 * decodes them, so each leaf is sliced from the source between its
	 * positions, whatever the leaf spells.
	 */
	private function getText(NodeInterface $node, string $source): ?string
	{
		if ($node instanceof SequenceNode) {
			$fullText = '';
			foreach ($node->children as $child) {
				$text = $this->getText($child, $source);
				if ($text === null) {
					continue;
				}

				$fullText .= $text;
			}

			if ($fullText === '') {
				return null;
			}

			return $fullText;
		}

		if ($node->getChildren() !== []) {
			return null;
		}

		return substr($source, $node->getStartPosition(), $node->getEndPosition() - $node->getStartPosition());
	}

	private function hasAnchorsInTheMiddle(NodeInterface $ast): bool
	{
		if ($ast instanceof AnchorNode) {
			return $ast->value === '$';
		}

		$children = $ast instanceof SequenceNode ? $ast->children : $ast->getChildren();
		$childrenCount = count($children);
		foreach ($children as $i => $child) {
			$has = $this->hasAnchorsInTheMiddle($child);
			if (
				$has
				&& (!$ast instanceof SequenceNode || $i !== $childrenCount - 1)
			) {
				return true;
			}
		}

		return false;
	}

}
