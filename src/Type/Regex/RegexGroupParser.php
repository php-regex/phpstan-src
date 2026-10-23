<?php declare(strict_types = 1);

namespace PHPStan\Type\Regex;

use Nette\Utils\RegexpException;
use Nette\Utils\Strings;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Php\PhpVersion;
use PHPStan\ShouldNotHappenException;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Accessory\AccessoryNonEmptyStringType;
use PHPStan\Type\Accessory\AccessoryNonFalsyStringType;
use PHPStan\Type\Accessory\AccessoryNumericStringType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\IntersectionType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\UnionType;
use function array_key_exists;
use function array_merge;
use function count;
use function in_array;
use function mb_str_split;
use function str_contains;
use function trim;

#[AutowiredService]
final class RegexGroupParser
{

	private const NOT_SUPPORTED_MODIFIERS = [
		'J', // rare modifier too complicated to support
	];

	// upper bound on the number of constant string literals enumerated from a group,
	// to avoid combinatorial explosion from nested optional/bounded quantifications
	private const LITERALS_LIMIT = 100;

	/** @var array<string, ?RegexNode> */
	private static array $parsedAst = [];

	public function __construct(
		private PhpVersion $phpVersion,
		private RegexExpressionHelper $regexExpressionHelper,
		private PHPRegexReader $reader,
	)
	{
	}

	public function parseGroups(string $regex): ?RegexAstWalkResult
	{
		// the walk is judged for a PHP version: the same regex read for two
		// versions must not share a tree
		$cacheKey = $this->phpVersion->getVersionId() . "\n" . $regex;

		if (array_key_exists($cacheKey, self::$parsedAst)) {
			$ast = self::$parsedAst[$cacheKey];
			if ($ast === null) {
				return null;
			}

			$modifiers = $this->regexExpressionHelper->getPatternModifiers($regex) ?? '';
		} else {
			try {
				Strings::match('', $regex);
			} catch (RegexpException) {
				// pattern is invalid, so let the RegularExpressionPatternRule report it
				return self::$parsedAst[$cacheKey] = null;
			}

			$modifiers = $this->regexExpressionHelper->getPatternModifiers($regex) ?? '';
			foreach (self::NOT_SUPPORTED_MODIFIERS as $notSupportedModifier) {
				if (str_contains($modifiers, $notSupportedModifier)) {
					return self::$parsedAst[$cacheKey] = null;
				}
			}

			$rawRegex = $this->regexExpressionHelper->removeDelimitersAndModifiers($regex);
			try {
				$ast = self::$parsedAst[$cacheKey] = $this->reader->read(
					$rawRegex,
					$modifiers,
					self::delimiterOf($regex),
				);
			} catch (RegexException) {
				return self::$parsedAst[$cacheKey] = null;
			}
		}

		$captureOnlyNamed = false;
		if ($this->phpVersion->supportsPregCaptureOnlyNamedGroups()) {
			$captureOnlyNamed = str_contains($modifiers, 'n');
		}

		$astWalkResult = $this->walkRegexAst(
			$ast->pattern,
			null,
			0,
			false,
			null,
			$captureOnlyNamed,
			false,
			$modifiers,
			RegexAstWalkResult::createEmpty(),
		);

		$subjectAsGroupResult = $this->walkGroupAst(
			$ast->pattern,
			false,
			$modifiers,
			RegexGroupWalkResult::createEmpty(),
		);

		if (!$subjectAsGroupResult->mightContainEmptyStringLiteral() && !$this->containsEscapeK($ast->pattern)) {
			// we could handle numeric-string, in case we know the regex is delimited by ^ and $
			if ($subjectAsGroupResult->isNonFalsy()->yes()) {
				$astWalkResult = $astWalkResult->withSubjectBaseType(
					new IntersectionType([new StringType(), new AccessoryNonFalsyStringType()]),
				);
			} elseif ($subjectAsGroupResult->isNonEmpty()->yes()) {
				$astWalkResult = $astWalkResult->withSubjectBaseType(
					new IntersectionType([new StringType(), new AccessoryNonEmptyStringType()]),
				);
			}
		}

		return $astWalkResult;
	}

	private static function delimiterOf(string $regex): string
	{
		$trimmed = trim($regex);

		return $trimmed === '' ? '/' : $trimmed[0];
	}

	private function containsEscapeK(NodeInterface $node): bool
	{
		if ($node instanceof KeepNode) {
			return true;
		}

		foreach ($node->getChildren() as $child) {
			if ($this->containsEscapeK($child)) {
				return true;
			}
		}

		return false;
	}

	private function walkRegexAst(
		NodeInterface $ast,
		?RegexAlternation $alternation,
		int $combinationIndex,
		bool $inOptionalQuantification,
		RegexCapturingGroup|RegexNonCapturingGroup|null $parentGroup,
		bool $captureOnlyNamed,
		bool $repeatedMoreThanOnce,
		string $patternModifiers,
		RegexAstWalkResult $astWalkResult,
	): RegexAstWalkResult
	{
		$group = null;
		if ($ast instanceof GroupNode) {
			if ($ast->type === GroupType::Capturing()) {
				$astWalkResult = $astWalkResult->nextCaptureGroupId();

				$group = new RegexCapturingGroup(
					$astWalkResult->getCaptureGroupId(),
					null,
					$alternation,
					$inOptionalQuantification,
					$parentGroup,
					$this->createGroupType(
						$ast,
						$this->allowConstantTypes($patternModifiers, $repeatedMoreThanOnce, $parentGroup),
						$patternModifiers,
					),
				);
				$parentGroup = $group;
			} elseif ($ast->type === GroupType::Named()) {
				$astWalkResult = $astWalkResult->nextCaptureGroupId();

				$group = new RegexCapturingGroup(
					$astWalkResult->getCaptureGroupId(),
					$ast->name,
					$alternation,
					$inOptionalQuantification,
					$parentGroup,
					$this->createGroupType(
						$ast,
						$this->allowConstantTypes($patternModifiers, $repeatedMoreThanOnce, $parentGroup),
						$patternModifiers,
					),
				);
				$parentGroup = $group;
			} elseif ($ast->type === GroupType::BranchReset()) {
				$group = new RegexNonCapturingGroup(
					$alternation,
					$inOptionalQuantification,
					$parentGroup,
					true,
				);
				$parentGroup = $group;
			} elseif ($ast->type !== GroupType::ScanSubstring()) {
				$group = new RegexNonCapturingGroup(
					$alternation,
					$inOptionalQuantification,
					$parentGroup,
					false,
				);
				$parentGroup = $group;
			}
		}

		$inOptionalQuantification = false;
		if ($ast instanceof QuantifierNode) {
			$bounds = QuantifierBounds::parse($ast->quantifier);
			if ($bounds === null) {
				throw new ShouldNotHappenException();
			}

			if ($bounds->min === 0) {
				$inOptionalQuantification = true;
			}

			if ($bounds->max === null || $bounds->max > 1) {
				$repeatedMoreThanOnce = true;
			}
		}

		if ($ast instanceof AlternationNode) {
			$astWalkResult = $astWalkResult->nextAlternationId();
			$alternation = new RegexAlternation($astWalkResult->getAlternationId(), count($ast->alternatives));
		}

		if ($ast instanceof PcreVerbNode && str_starts_with($ast->verb, 'MARK:')) {
			return $astWalkResult->markVerb(substr($ast->verb, 5));
		}

		if (
			$group instanceof RegexCapturingGroup &&
			(!$captureOnlyNamed || $group->isNamed())
		) {
			$astWalkResult = $astWalkResult->addCapturingGroup($group);

			if ($alternation !== null) {
				$alternation->pushGroup($combinationIndex, $group);
			}
		}

		// a conditional holds its yes and no branches as the engine reads
		// them: the groups they carry sit in one alternation
		if ($ast instanceof ConditionalNode || $ast instanceof DefineNode) {
			$astWalkResult = $this->walkRegexAst(
				$ast instanceof ConditionalNode ? $ast->condition : $ast->content,
				$alternation,
				$combinationIndex,
				$inOptionalQuantification,
				$parentGroup,
				$captureOnlyNamed,
				$repeatedMoreThanOnce,
				$patternModifiers,
				$astWalkResult,
			);
		}

		$branches = $ast instanceof AlternationNode
			? $ast->alternatives
			: $ast->getChildren();

		foreach ($branches as $i => $branch) {
			$astWalkResult = $this->walkRegexAst(
				$branch,
				$alternation,
				$ast instanceof AlternationNode ? $i : $combinationIndex,
				$inOptionalQuantification,
				$parentGroup,
				$captureOnlyNamed,
				$repeatedMoreThanOnce,
				$patternModifiers,
				$astWalkResult,
			);
		}

		if ($ast instanceof ConditionalNode || $ast instanceof DefineNode) {
			$yesNo = $ast instanceof ConditionalNode
				? [$ast->yes, $ast->no]
				: [$ast->content];
			$astWalkResult = $astWalkResult->nextAlternationId();
			$conditionAlternation = new RegexAlternation($astWalkResult->getAlternationId(), count($yesNo));

			foreach ($yesNo as $i => $branch) {
				$astWalkResult = $this->walkRegexAst(
					$branch,
					$conditionAlternation,
					$i,
					$inOptionalQuantification,
					$parentGroup,
					$captureOnlyNamed,
					$repeatedMoreThanOnce,
					$patternModifiers,
					$astWalkResult,
				);
			}
		}

		return $astWalkResult;
	}

	private function allowConstantTypes(
		string $patternModifiers,
		bool $repeatedMoreThanOnce,
		RegexCapturingGroup|RegexNonCapturingGroup|null $parentGroup,
	): bool
	{
		if (str_contains($patternModifiers, 'i')) {
			// if caseless, we don't use constant types
			// because it likely yields too many combinations
			return false;
		}

		if ($repeatedMoreThanOnce) {
			return false;
		}

		if ($parentGroup !== null && $parentGroup->resetsGroupCounter()) {
			return false;
		}

		return true;
	}

	private function createGroupType(GroupNode $group, bool $maybeConstant, string $patternModifiers): Type
	{
		$rootAlternation = $this->getRootAlternation($group);
		if ($rootAlternation !== null) {
			$types = [];
			foreach ($rootAlternation->alternatives as $alternative) {
				$types[] = $this->createGroupTypeFromNode($alternative, $maybeConstant, $patternModifiers);
			}

			return TypeCombinator::union(...$types);
		}

		return $this->createGroupTypeFromNode($group->child, $maybeConstant, $patternModifiers);
	}

	private function createGroupTypeFromNode(NodeInterface $node, bool $maybeConstant, string $patternModifiers): Type
	{
		$walkResult = $this->walkGroupAst(
			$node,
			false,
			$patternModifiers,
			RegexGroupWalkResult::createEmpty(),
		);

		if ($maybeConstant && $walkResult->getOnlyLiterals() !== null && $walkResult->getOnlyLiterals() !== []) {
			$result = [];
			foreach ($walkResult->getOnlyLiterals() as $literal) {
				$result[] = new ConstantStringType($literal);

			}
			return TypeCombinator::union(...$result);
		}

		if ($walkResult->isNumeric()->yes()) {
			if ($walkResult->isNonFalsy()->yes()) {
				return new IntersectionType([
					new StringType(),
					new AccessoryNumericStringType(),
					new AccessoryNonFalsyStringType(),
				]);
			}

			$result = new IntersectionType([new StringType(), new AccessoryNumericStringType()]);
			if (!$walkResult->isNonEmpty()->yes()) {
				return new UnionType([new ConstantStringType(''), $result]);
			}
			return $result;
		} elseif ($walkResult->isNonFalsy()->yes()) {
			return new IntersectionType([new StringType(), new AccessoryNonFalsyStringType()]);
		} elseif ($walkResult->isNonEmpty()->yes()) {
			return new IntersectionType([new StringType(), new AccessoryNonEmptyStringType()]);
		}

		return new StringType();
	}

	private function getRootAlternation(GroupNode $group): ?AlternationNode
	{
		if ($group->child instanceof AlternationNode) {
			return $group->child;
		}

		return null;
	}

	private function walkGroupAst(
		NodeInterface $ast,
		bool $inClass,
		string $patternModifiers,
		RegexGroupWalkResult $walkResult,
	): RegexGroupWalkResult
	{
		$children = $ast->getChildren();
		$quantifiedLiterals = null;

		if (
			$ast instanceof SequenceNode
			&& count($children) > 0
			&& !$walkResult->isInOptionalQuantification()
		) {
			$meaningfulTokens = 0;
			foreach ($children as $child) {
				$nonFalsy = false;
				if ($this->isMaybeEmptyNode($child, $patternModifiers, $nonFalsy)) {
					continue;
				}

				$meaningfulTokens++;

				if (!$nonFalsy) {
					continue;
				}

				// a single token non-falsy on its own
				$walkResult = $walkResult->nonFalsy(TrinaryLogic::createYes());
				break;
			}

			if ($meaningfulTokens > 0) {
				$walkResult = $walkResult->nonEmpty(TrinaryLogic::createYes());

				// two non-empty tokens concatenated results in a non-falsy string
				if ($meaningfulTokens > 1) {
					$walkResult = $walkResult->nonFalsy(TrinaryLogic::createYes());
				}
			}
		} elseif ($ast instanceof QuantifierNode) {
			$bounds = QuantifierBounds::parse($ast->quantifier);
			[$min, $max] = $bounds === null ? [null, null] : [$bounds->min, $bounds->max];

			if ($min === 0) {
				$walkResult = $walkResult->inOptionalQuantification(true);
			}

			if (!$walkResult->isInOptionalQuantification()) {
				if ($min !== null && $min >= 1) {
					$walkResult = $walkResult->nonEmpty(TrinaryLogic::createYes());
				}
				if ($min !== null && $min >= 2) {
					$walkResult = $walkResult->nonFalsy(TrinaryLogic::createYes());
				}
			}

			// "a?" yields 'a'|'', "a{1,2}" yields 'a'|'aa', etc. so a bounded quantification
			// over constant literals can be combined with the surrounding literals
			$quantifiedLiterals = $this->getQuantifiedLiterals($ast, $min, $max, $inClass, $patternModifiers, $walkResult);

			$walkResult = $walkResult->onlyLiterals(null);
		} elseif ($ast instanceof CharClassNode && !$ast->isNegated && $walkResult->getOnlyLiterals() !== null) {
			$inClass = true;

			$newLiterals = [];
			$members = $ast->expression instanceof AlternationNode
				? $ast->expression->alternatives
				: [$ast->expression];
			foreach ($this->classMembers($members) as $member) {
				$oldLiterals = $walkResult->getOnlyLiterals();

				$this->getLiteralValue($member, $oldLiterals, true, $patternModifiers, true);
				foreach ($oldLiterals ?? [] as $oldLiteral) {
					$newLiterals[] = $oldLiteral;
				}
			}
			$walkResult = $walkResult->onlyLiterals($newLiterals);
		} elseif ($ast instanceof LiteralNode) {
			$onlyLiterals = $walkResult->getOnlyLiterals();
			$literalValue = $this->getLiteralValue($ast, $onlyLiterals, !$inClass, $patternModifiers, false);
			$walkResult = $walkResult->onlyLiterals($onlyLiterals);

			if ($literalValue !== null) {
				if (Strings::match($literalValue, '/^\d+$/') === null) {
					$walkResult = $walkResult->numeric(TrinaryLogic::createNo());
				} elseif ($walkResult->isNumeric()->maybe()) {
					$walkResult = $walkResult->numeric(TrinaryLogic::createYes());
				}

				if (!$walkResult->isInOptionalQuantification() && $literalValue !== '') {
					$walkResult = $walkResult->nonEmpty(TrinaryLogic::createYes());
				}
			}
		} elseif (
			!($ast instanceof GroupNode
				&& ($ast->type === GroupType::Capturing() || $ast->type === GroupType::Named()))
			&& !$ast instanceof AlternationNode
			&& !$ast instanceof LiteralNode
		) {
			// every other leaf and group the walk does not fold into constants
			// drops them: an escape, a wildcard, a backreference, a verb —
			// even a \d that reads as '0' — just as every token outside the
			// literal family did
			$literalValue = $this->getLiteralValue($ast, $onlyLiterals, !$inClass, $patternModifiers, false);
			$walkResult = $walkResult->onlyLiterals(null);

			if ($literalValue !== null) {
				if (Strings::match($literalValue, '/^\d+$/') === null) {
					$walkResult = $walkResult->numeric(TrinaryLogic::createNo());
				} elseif ($walkResult->isNumeric()->maybe()) {
					$walkResult = $walkResult->numeric(TrinaryLogic::createYes());
				}

				if (!$walkResult->isInOptionalQuantification() && $literalValue !== '') {
					$walkResult = $walkResult->nonEmpty(TrinaryLogic::createYes());
				}
			}
		}

		if ($ast instanceof AlternationNode) {
			if (count($ast->alternatives) === 0) {
				return $walkResult;
			}

			// literals accumulated before the alternation form a common prefix that has to be
			// combined with every branch, e.g. "a(b|c)" yields "ab"|"ac", not "b"|"c"
			$prefixLiterals = $walkResult->getOnlyLiterals();
			$newLiterals = [];
			$nonEmpty = TrinaryLogic::createYes();
			$nonFalsy = TrinaryLogic::createYes();
			$numeric = TrinaryLogic::createYes();
			foreach ($ast->alternatives as $alternative) {
				$childResult = $this->walkGroupAst(
					$alternative,
					$inClass,
					$patternModifiers,
					$walkResult->onlyLiterals([])
						->nonEmpty(TrinaryLogic::createMaybe())
						->nonFalsy(TrinaryLogic::createMaybe())
						->numeric(TrinaryLogic::createMaybe()),
				);

				$nonEmpty = $nonEmpty->and($childResult->isNonEmpty());
				$nonFalsy = $nonFalsy->and($childResult->isNonFalsy());
				$numeric = $numeric->and($childResult->isNumeric());

				if ($newLiterals === null) {
					continue;
				}

				$childLiterals = $childResult->getOnlyLiterals();
				if ($prefixLiterals === null || $childLiterals === null || count($childLiterals) === 0) {
					$newLiterals = null;
					continue;
				}

				foreach ($childLiterals as $childLiteral) {
					if ($prefixLiterals === []) {
						$newLiterals[] = $childLiteral;
					} else {
						foreach ($prefixLiterals as $prefixLiteral) {
							$newLiterals[] = $prefixLiteral . $childLiteral;
						}
					}
				}
			}

			return $walkResult
				->onlyLiterals($newLiterals)
				->nonEmpty($walkResult->isNonEmpty()->or($nonEmpty))
				->nonFalsy($walkResult->isNonFalsy()->or($nonFalsy))
				->numeric($walkResult->isNumeric()->and($numeric));
		}

		// [^0-9] should not parse as numeric-string, and [^list-everything-but-numbers] is technically
		// doable but really silly compared to just \d so we can safely assume the string is not numeric
		// for negative classes
		if ($ast instanceof CharClassNode && $ast->isNegated) {
			$walkResult = $walkResult->numeric(TrinaryLogic::createNo());
		}

		// the members of a class the combination above already folded walk one
		// by one below, as they did before, without the wrapper that groups
		// them in the tree
		$descend = $ast instanceof CharClassNode
			? $this->classMembers($ast->expression instanceof AlternationNode
				? $ast->expression->alternatives
				: [$ast->expression])
			: $children;

		foreach ($descend as $child) {
			$walkResult = $this->walkGroupAst(
				$child,
				$inClass,
				$patternModifiers,
				$walkResult,
			);
		}

		if ($ast instanceof QuantifierNode) {
			// the bottom walk above nulls the literals via the quantifier node,
			// so restore the literals enumerated up-front for bounded quantifications
			$walkResult = $walkResult->onlyLiterals($quantifiedLiterals);
		}

		return $walkResult;
	}

	/**
	 * Enumerate the constant strings a bounded quantification (like "a?", "a{2}", "a{1,3}")
	 * produces, combined with the literals accumulated so far. Returns null when the result
	 * cannot be enumerated (unbounded quantifier, non-literal atom, or too many combinations).
	 *
	 * @return array<string>|null
	 */
	private function getQuantifiedLiterals(QuantifierNode $ast, ?int $min, ?int $max, bool $inClass, string $patternModifiers, RegexGroupWalkResult $walkResult): ?array
	{
		$prefixLiterals = $walkResult->getOnlyLiterals();
		if ($prefixLiterals === null || $min === null || $max === null) {
			return null;
		}

		// walk the quantified atom standalone; the atom itself is not optional,
		// so reset the flag to let concatenations accumulate literals
		$atomResult = $walkResult->onlyLiterals([])->inOptionalQuantification(false);
		$atomResult = $this->walkGroupAst($ast->node, $inClass, $patternModifiers, $atomResult);

		$atomLiterals = $atomResult->getOnlyLiterals();
		if ($atomLiterals === null) {
			return null;
		}

		$repeatedLiterals = $this->repeatLiterals($atomLiterals, $min, $max);
		if ($repeatedLiterals === null) {
			return null;
		}

		$newLiterals = [];
		foreach ($repeatedLiterals as $repeatedLiteral) {
			if ($prefixLiterals === []) {
				$newLiterals[] = $repeatedLiteral;
			} else {
				foreach ($prefixLiterals as $prefixLiteral) {
					$newLiterals[] = $prefixLiteral . $repeatedLiteral;
				}
			}

			if (count($newLiterals) > self::LITERALS_LIMIT) {
				return null;
			}
		}

		return $newLiterals;
	}

	/**
	 * @param array<string> $literals
	 * @return array<string>|null
	 */
	private function repeatLiterals(array $literals, int $min, int $max): ?array
	{
		$collected = [];
		if ($min === 0) {
			$collected[''] = '';
		}

		$current = [''];
		for ($k = 1; $k <= $max; $k++) {
			$next = [];
			foreach ($current as $prefix) {
				foreach ($literals as $literal) {
					$next[] = $prefix . $literal;
				}

				if (count($next) > self::LITERALS_LIMIT) {
					return null;
				}
			}
			$current = $next;

			if ($k < $min) {
				continue;
			}

			foreach ($current as $value) {
				$collected[$value] = $value;
			}

			if (count($collected) > self::LITERALS_LIMIT) {
				return null;
			}
		}

		return array_values($collected);
	}

	private function isMaybeEmptyNode(NodeInterface $node, string $patternModifiers, bool &$isNonFalsy): bool
	{
		if ($node instanceof QuantifierNode) {
			$bounds = QuantifierBounds::parse($node->quantifier);
			$min = $bounds === null ? null : $bounds->min;

			if ($min !== null && $min > 0) {
				return false;
			}

			if ($min === 0) {
				return true;
			}
		}

		$literal = $this->getLiteralValue($node, $onlyLiterals, false, $patternModifiers, false);
		if ($literal !== null) {
			if ($literal !== '' && $literal !== '0') {
				$isNonFalsy = true;
			}
			return $literal === '';
		}

		// an alternation is maybe-empty if any of its branches is maybe-empty,
		// and non-falsy only if every branch is non-falsy
		if ($node instanceof AlternationNode) {
			$maybeEmpty = false;
			$allNonFalsy = true;
			foreach ($node->alternatives as $alternative) {
				$childNonFalsy = false;
				if ($this->isMaybeEmptyNode($alternative, $patternModifiers, $childNonFalsy)) {
					$maybeEmpty = true;
				}
				$allNonFalsy = $allNonFalsy && $childNonFalsy;
			}

			if ($allNonFalsy) {
				$isNonFalsy = true;
			}

			return $maybeEmpty;
		}

		foreach ($node->getChildren() as $child) {
			if (!$this->isMaybeEmptyNode($child, $patternModifiers, $isNonFalsy)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The members a character class enumerates, one array entry per
	 * character, as the engine reads them. A range keeps its own node: it
	 * enumerates no constant of its own and drops the constants around it,
	 * as it always did.
	 *
	 * @param list<NodeInterface> $members
	 * @return list<NodeInterface>
	 */
	private function classMembers(array $members): array
	{
		$result = [];
		foreach ($members as $member) {
			if ($member instanceof LiteralNode) {
				foreach (mb_str_split($member->value, encoding: 'UTF-8') as $char) {
					$result[] = new LiteralNode($char, $member->getStartPosition(), $member->getEndPosition());
				}
				continue;
			}

			$result[] = $member;
		}

		return $result;
	}

	/**
	 * @param array<string>|null $onlyLiterals
	 */
	private function getLiteralValue(NodeInterface $node, ?array &$onlyLiterals, bool $appendLiterals, string $patternModifiers, bool $inCharacterClass): ?string
	{
		if ($node instanceof LiteralNode) {
			if (str_contains($patternModifiers, 'x') && trim($node->value) === '') {
				return null;
			}

			// one literal per character, as the walk counts tokens, the whole
			// run back as the value; an empty run reads as the empty string,
			// as the empty branch of an alternation always did; a literal
			// "." outside a class is a constant, the wildcard is its own node
			$chars = $node->value === '' ? [''] : mb_str_split($node->value, encoding: 'UTF-8');

			if ($appendLiterals && $onlyLiterals !== null) {
				foreach ($chars as $char) {
					if ($onlyLiterals === []) {
						$onlyLiterals = [$char];
					} else {
						foreach ($onlyLiterals as &$literal) {
							$literal .= $char;
						}
					}
				}
			}

			return $node->value;
		}

		if ($node instanceof DotNode) {
			// the wildcard matches anything: no constant literals survive it
			$onlyLiterals = null;

			return '.';
		}

		if ($node instanceof CharTypeNode) {
			if (!$inCharacterClass && $node->value === 'd') {
				return '0';
			}

			return '\\' . $node->value;
		}

		if ($node instanceof UnicodePropNode) {
			return '\\' . ($node->negatedSyntax ? 'P' : 'p') . $node->prop;
		}

		if ($node instanceof CharLiteralNode) {
			return mb_chr($node->codePoint, 'UTF-8') ?? '';
		}

		if ($node instanceof ControlCharNode) {
			return mb_chr($node->codePoint, 'UTF-8') ?? '';
		}

		// [:digit:] and the like
		if ($node instanceof PosixClassNode) {
			if ($node->class === 'digit') {
				return '0';
			}
			if (in_array($node->class, ['alpha', 'alnum', 'upper', 'lower', 'word', 'ascii', 'print', 'xdigit', 'graph'], true)) {
				return 'a';
			}
			if ($node->class === 'blank') {
				return " \t";
			}
			if ($node->class === 'cntrl') {
				return "\x00\x1F";
			}
			if ($node->class === 'space') {
				return " \t\r\n\v\f";
			}
			if ($node->class === 'punct') {
				return '!"#$%&\'()*+,\-./:;<=>?@[\]^_`{|}~';
			}
		}

		if ($node instanceof AnchorNode || $node instanceof AssertionNode || $node instanceof KeepNode) {
			return '';
		}

		// backreferences, subroutine calls, verbs, callouts: their content is
		// not a constant, and no constant literals survive them
		$onlyLiterals = null;

		return null;
	}

}
