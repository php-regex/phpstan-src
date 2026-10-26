<?php declare(strict_types = 1);

namespace PHPStan\Type\Regex;

use PHPRegex\Parser\Analysis\CaptureGroupShape;
use PHPRegex\Parser\Analysis\CaptureShape;
use PHPRegex\Parser\Analysis\CaptureShapeAnalyzer;
use PHPRegex\Parser\Analysis\Participation;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Php\PhpVersion;
use PHPStan\Type\Accessory\AccessoryDecimalIntegerStringType;
use PHPStan\Type\Accessory\AccessoryLowercaseStringType;
use PHPStan\Type\Accessory\AccessoryNonEmptyStringType;
use PHPStan\Type\Accessory\AccessoryNonFalsyStringType;
use PHPStan\Type\Accessory\AccessoryNumericStringType;
use PHPStan\Type\Accessory\AccessoryUppercaseStringType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\IntersectionType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\UnionType;
use function array_filter;
use function array_key_exists;
use function array_unique;
use function count;
use function max;
use function spl_object_id;

/**
 * Builds the capturing group list RegexArrayShapeMatcher turns into $matches types.
 *
 * The value of each group comes from PHPRegex's CaptureShapeAnalyzer. This class
 * only walks the tree to find where each group sits: its parent group, and the
 * alternation branch it belongs to.
 */
#[AutowiredService]
final class RegexGroupParser
{

	/** @var array<string, ?RegexAstWalkResult> */
	private static array $parsedShapes = [];

	public function __construct(
		private PhpVersion $phpVersion,
		private PHPRegexReader $reader,
	)
	{
	}

	public function parseGroups(string $regex): ?RegexAstWalkResult
	{
		// the same pattern can be read differently for another PHP or PCRE2 version
		$cacheKey = $this->phpVersion->getVersionId() . "\n" . $this->reader->judgedPcreVersion() . "\n" . $regex;

		if (array_key_exists($cacheKey, self::$parsedShapes)) {
			return self::$parsedShapes[$cacheKey];
		}

		$ast = $this->reader->readExpression($regex);
		if ($ast === null) {
			return self::$parsedShapes[$cacheKey] = null;
		}

		$shape = (new CaptureShapeAnalyzer())->analyze($ast);

		return self::$parsedShapes[$cacheKey] = $this->buildWalkResult($shape, $ast->pattern);
	}

	private function buildWalkResult(CaptureShape $shape, NodeInterface $pattern): RegexAstWalkResult
	{
		$topology = new RegexTopology();
		$this->place($pattern, $topology, null, null, 0);

		$walkResult = RegexAstWalkResult::createEmpty();
		foreach ($shape->marks as $mark) {
			$walkResult = $walkResult->markVerb($mark);
		}

		$alternations = [];
		foreach ($topology->getAlternations() as $node) {
			$walkResult = $walkResult->nextAlternationId();
			$alternations[spl_object_id($node)] = new RegexAlternation(
				$walkResult->getAlternationId(),
				$topology->getBranchCount($node),
			);
		}

		$walkResult = $this->addGroups($shape, $topology, $alternations, $walkResult);

		$containsEscapeK = $this->containsEscapeK($pattern);

		return $walkResult
			->withSubjectBaseType($containsEscapeK ? new StringType() : $this->typeOf($shape->whole))
			->withSubjectNarrowType($this->subjectNarrowType($shape))
			// a scan resumes after an empty match, where a zero-width first branch like ^ no longer matches
			->withScanBaseType($shape->scanWhole === null || $containsEscapeK ? null : $this->typeOf($shape->scanWhole))
			->withCases($this->cases($shape));
	}

	/**
	 * Creates the group objects in tree order, so that a parent group, capturing
	 * or not, exists before its children.
	 *
	 * @param array<int, RegexAlternation> $alternations by alternation node id
	 */
	private function addGroups(CaptureShape $shape, RegexTopology $topology, array $alternations, RegexAstWalkResult $walkResult): RegexAstWalkResult
	{
		$everyBranchAlternations = $this->alternationsCapturingInEveryBranch($topology);
		$placementsByNumber = $topology->getPlacementsByNumber();

		$groupShapes = [];
		foreach ($shape->groups as $groupShape) {
			$groupShapes[$groupShape->number] = $groupShape;
		}

		/** @var array<int, RegexCapturingGroup|RegexNonCapturingGroup> $objects by node id */
		$objects = [];
		/** @var array<int, RegexCapturingGroup> $capturingGroups by number */
		$capturingGroups = [];
		foreach ($topology->getPlacements() as $placement) {
			$parent = $placement->getParentNodeId() !== null ? ($objects[$placement->getParentNodeId()] ?? null) : null;
			$number = $placement->getNumber();

			if ($number === null) {
				$objects[$placement->getNodeId()] = new RegexNonCapturingGroup(
					$placement->getAlternation() !== null ? ($alternations[spl_object_id($placement->getAlternation())] ?? null) : null,
					$placement->inOptionalQuantification(),
					$parent,
					$placement->resetsGroupCounter(),
				);
				continue;
			}

			// in a branch reset, several nodes share one group number
			if (isset($capturingGroups[$number])) {
				$objects[$placement->getNodeId()] = $capturingGroups[$number];
				continue;
			}

			$groupShape = $groupShapes[$number] ?? null;
			if ($groupShape === null) {
				continue;
			}

			// a number captured in several branches of a branch reset sits where its
			// last placement does, and in the branches of all of them
			$placements = $placementsByNumber[$number];
			$last = $placements[count($placements) - 1];
			$groupAlternation = $last->getAlternation();
			$inOptional = $last->inOptionalQuantification();

			// a branch reset whose groups all capture in every branch sets them all, whichever branch matches
			if ($groupAlternation !== null && isset($everyBranchAlternations[spl_object_id($groupAlternation)])) {
				$groupAlternation = null;
			}

			// e.g. a group inside (?(DEFINE)...): it is numbered but never set
			$neverMatched = $groupShape->participation === Participation::Never();
			$type = $neverMatched
				? new ConstantStringType('')
				: $this->typeOf($groupShape);

			// the analysis can know that a group may be unset for reasons the tree
			// walk does not see, e.g. a (*ACCEPT) before it or a negative lookaround around it
			$unsetUnseen = $neverMatched
				|| (
					$groupShape->participation === Participation::MayBeUnset()
					&& !$inOptional
					&& ($parent === null || !$parent->isOptional())
					&& ($groupAlternation === null || $this->mayBeUnsetInItsBranch($shape, $number))
				);
			if ($unsetUnseen) {
				$inOptional = true;
			}

			$alternation = $groupAlternation !== null ? ($alternations[spl_object_id($groupAlternation)] ?? null) : null;
			$group = new RegexCapturingGroup(
				$groupShape->number,
				$groupShape->name,
				$alternation,
				$inOptional,
				$parent,
				$type,
				neverMatched: $neverMatched,
			);
			$objects[$placement->getNodeId()] = $group;
			$capturingGroups[$number] = $group;

			$walkResult = $walkResult->addCapturingGroup($group);

			if ($alternation === null) {
				continue;
			}

			foreach ($placements as $other) {
				$alternation->pushGroup($other->getBranch(), $group);
			}
		}

		return $walkResult;
	}

	/**
	 * The alternations whose capturing groups all capture in every branch: in such
	 * a branch reset, every group is set whichever branch matches.
	 *
	 * @return array<int, true> by alternation node id
	 */
	private function alternationsCapturingInEveryBranch(RegexTopology $topology): array
	{
		$everyBranch = [];
		foreach ($topology->getPlacementsByNumber() as $placements) {
			$alternation = $placements[count($placements) - 1]->getAlternation();
			if ($alternation === null) {
				continue;
			}

			$branches = [];
			foreach ($placements as $placement) {
				$branches[] = $placement->getBranch();
			}

			$id = spl_object_id($alternation);
			$inEveryBranch = count(array_unique($branches)) === $topology->getBranchCount($alternation);
			$everyBranch[$id] = ($everyBranch[$id] ?? true) && $inEveryBranch;
		}

		return array_filter($everyBranch);
	}

	/**
	 * A pattern pinned at both ends tells what the whole subject holds, a final newline
	 * included where $ lets one through; otherwise the subject contains the match,
	 * so it is non-empty or non-falsy when the match is.
	 */
	private function subjectNarrowType(CaptureShape $shape): Type
	{
		if ($shape->subject !== null) {
			return $this->typeOf($shape->subject);
		}

		if ($shape->whole->nonFalsy) {
			return new IntersectionType([new StringType(), new AccessoryNonFalsyStringType()]);
		}

		if ($shape->whole->minLength >= 1) {
			return new IntersectionType([new StringType(), new AccessoryNonEmptyStringType()]);
		}

		return new StringType();
	}

	/**
	 * The analysis splits a pattern into cases whose union covers every match,
	 * e.g. an optional group that runs with its nested groups or is skipped with them.
	 *
	 * @return list<array<int, bool>>
	 */
	private function cases(CaptureShape $shape): array
	{
		$cases = [];
		foreach ($shape->cases as $case) {
			$participations = [];
			foreach ($case->groups as $groupShape) {
				if ($groupShape->participation === Participation::Always()) {
					$participations[$groupShape->number] = true;
				} elseif ($groupShape->participation === Participation::Never()) {
					$participations[$groupShape->number] = false;
				}
			}
			$cases[] = $participations;
		}

		return $cases;
	}

	/**
	 * Whether a group of an alternation may be unset even when its branch matches,
	 * e.g. (c) in /(a)|b(*ACCEPT)(c)/. Each case of the shape covers the matches of one branch.
	 */
	private function mayBeUnsetInItsBranch(CaptureShape $shape, int $number): bool
	{
		foreach ($shape->cases as $case) {
			if (isset($case->groups[$number]) && $case->groups[$number]->participation === Participation::MayBeUnset()) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Numbers the capturing groups the way PCRE does, and records for each one
	 * its parent group, the alternation above it and its branch in that alternation.
	 */
	private function place(
		NodeInterface $node,
		RegexTopology $topology,
		?int $parentId,
		?NodeInterface $alternation,
		int $branch,
		bool $inOptionalQuantification = false,
		bool $inRepetition = false,
	): void
	{
		if ($node instanceof AlternationNode) {
			// a repeated alternation sets the groups of several branches: /(?:(a)|(b))+/ writes ['ab', 'a', 'b']
			if ($inRepetition) {
				foreach ($node->alternatives as $alternative) {
					$this->place($alternative, $topology, $parentId, null, 0, false, true);
				}

				return;
			}

			$topology->addAlternation($node, count($node->alternatives));
			foreach ($node->alternatives as $i => $alternative) {
				$this->place($alternative, $topology, $parentId, $node, $i);
			}

			return;
		}

		if ($node instanceof DefineNode) {
			// groups inside (?(DEFINE)...) are never set; an optional parent makes them optional
			$topology->addPlacement(new RegexGroupPlacement(spl_object_id($node), null, $parentId, $alternation, $branch, true, false));
			$this->place($node->content, $topology, spl_object_id($node), null, 0, false, $inRepetition);

			return;
		}

		if ($node instanceof ConditionalNode && !$inRepetition) {
			// groups in a lookaround condition are numbered first; only one of the
			// two branches runs, so they are read like an alternation
			$topology->addAlternation($node, 2);
			$this->place($node->condition, $topology, $parentId, null, 0);
			$this->place($node->yes, $topology, $parentId, $node, 0);
			$this->place($node->no, $topology, $parentId, $node, 1);

			return;
		}

		// only the node directly under the quantifier is marked optional,
		// nested groups get it through their parent
		if ($node instanceof QuantifierNode) {
			$bounds = QuantifierBounds::parse($node->quantifier);
			$this->place(
				$node->node,
				$topology,
				$parentId,
				$alternation,
				$branch,
				$bounds !== null && $bounds->min === 0,
				$inRepetition || $bounds === null || $bounds->max === null || $bounds->max > 1,
			);

			return;
		}

		if ($node instanceof GroupNode && $node->type === GroupType::BranchReset()) {
			// (?|...): every branch numbers its groups from the same number,
			// and the group after it continues after the longest branch
			$topology->addPlacement(new RegexGroupPlacement(spl_object_id($node), null, $parentId, $alternation, $branch, $inOptionalQuantification, true));
			$base = $topology->getNextNumber();
			$end = $base;
			$branchAlternation = $node->child instanceof AlternationNode && !$inRepetition ? $node->child : null;
			if ($branchAlternation !== null) {
				$topology->addAlternation($branchAlternation, count($branchAlternation->alternatives));
				$branches = $branchAlternation->alternatives;
			} else {
				$branches = $node->child instanceof AlternationNode ? $node->child->alternatives : [$node->child];
			}
			foreach ($branches as $i => $branchNode) {
				$topology->setNextNumber($base);
				$this->place($branchNode, $topology, spl_object_id($node), $branchAlternation, $branchAlternation === null ? 0 : $i, $inOptionalQuantification, $inRepetition);
				$end = max($end, $topology->getNextNumber());
			}
			$topology->setNextNumber($end);

			return;
		}

		if ($node instanceof GroupNode) {
			$capturing = $node->type === GroupType::Capturing() || $node->type === GroupType::Named();
			$topology->addPlacement(new RegexGroupPlacement(spl_object_id($node), $capturing ? $topology->takeNumber() : null, $parentId, $alternation, $branch, $inOptionalQuantification, false));
			$parentId = spl_object_id($node);
		}

		foreach ($node->getChildren() as $child) {
			$this->place($child, $topology, $parentId, $alternation, $branch, false, $inRepetition);
		}
	}

	/**
	 * A group that reads either constants or digits, like (a|\d), keeps each arm: 'a'|numeric-string.
	 */
	private function typeOf(CaptureGroupShape $shape): Type
	{
		if ($shape->arms === []) {
			return $this->stringTypeOf($shape);
		}

		$types = [];
		foreach ($shape->arms as $arm) {
			$types[] = $this->stringTypeOf($arm);
		}

		return TypeCombinator::union(...$types);
	}

	private function stringTypeOf(CaptureGroupShape $shape): Type
	{
		if ($shape->values !== null && $shape->values !== []) {
			$types = [];
			foreach ($shape->values as $value) {
				$types[] = new ConstantStringType($value);
			}

			return TypeCombinator::union(...$types);
		}

		$accessories = [];
		if ($shape->decimalInt) {
			$accessories[] = new AccessoryDecimalIntegerStringType();
		} elseif ($shape->digitsOnly) {
			$accessories[] = new AccessoryNumericStringType();
		}
		if ($shape->nonFalsy) {
			$accessories[] = new AccessoryNonFalsyStringType();
		} elseif ($shape->minLength >= 1 && !$shape->digitsOnly && !$shape->decimalInt) {
			$accessories[] = new AccessoryNonEmptyStringType();
		}
		// a case is kept when it alone holds: a value with no letter, digits among them, is both
		if (!$shape->digitsOnly && !$shape->decimalInt && $shape->lowercase !== $shape->uppercase) {
			$accessories[] = $shape->lowercase ? new AccessoryLowercaseStringType() : new AccessoryUppercaseStringType();
		}

		$type = $accessories === [] ? new StringType() : new IntersectionType([new StringType(), ...$accessories]);

		// a run of digits of any length is digits or the empty string
		if ($shape->digitsOnly && !$shape->decimalInt && $shape->minLength === 0) {
			return new UnionType([new ConstantStringType(''), $type]);
		}

		return $type;
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

}
