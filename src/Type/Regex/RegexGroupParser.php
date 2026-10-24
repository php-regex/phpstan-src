<?php declare(strict_types = 1);

namespace PHPStan\Type\Regex;

use PHPRegex\Parser\Analysis\CaptureGroupShape;
use PHPRegex\Parser\Analysis\CaptureShape;
use PHPRegex\Parser\Analysis\CaptureShapeAnalyzer;
use PHPRegex\Parser\Analysis\Participation;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Php\PhpVersion;
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
use function count;
use function spl_object_id;

/**
 * Reads a pattern into the group topology the array shape matcher folds
 * into $matches types: what each group captures comes from the parser's
 * own capture shape analysis, where it lives beside the patterns it reads,
 * and only the topology — which group sits in which branch of which
 * alternation — is walked here.
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
		// the shape is judged for a PHP version and the engine its target
		// runs: the same regex read for two of either must not share a shape
		$cacheKey = $this->phpVersion->getVersionId() . "\n" . $this->reader->judgedPcreVersion() . "\n" . $regex;

		// the pattern is judged for the analysed PHP and the engine its
		// target runs: one that engine refuses carries no shape, whatever
		// the running engine says about it
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

	/**
	 * The shape says what every group captures; the tree says where every
	 * group sits. Together they are the walk result the matcher folds.
	 */
	private function buildWalkResult(CaptureShape $shape, NodeInterface $pattern): RegexAstWalkResult
	{
		$topology = $this->collectTopology($pattern, null, null, 0, ['alternationNodes' => [], 'groups' => [], 'nonCapturing' => [], 'nextNumber' => 1]);

		$walkResult = RegexAstWalkResult::createEmpty();
		foreach ($shape->marks as $mark) {
			$walkResult = $walkResult->markVerb($mark);
		}

		$alternations = [];
		foreach ($topology['alternationNodes'] as $alternationNode) {
			$walkResult = $walkResult->nextAlternationId();
			$alternations[spl_object_id($alternationNode['node'])] = new RegexAlternation(
				$walkResult->getAlternationId(),
				$alternationNode['branches'],
			);
		}

		// numbered groups first, then the non-capturing ones above them,
		// so every parent object exists before its children ask for it
		$objects = [];
		foreach ($topology['nonCapturing'] as $id => $nonCapturing) {
			$objects[$id] = new RegexNonCapturingGroup(
				$nonCapturing['alternation'] !== null ? ($alternations[spl_object_id($nonCapturing['alternation'])] ?? null) : null,
				$nonCapturing['inOptional'],
				$nonCapturing['parent'] !== null ? ($objects[$nonCapturing['parent']] ?? null) : null,
				$nonCapturing['resets'],
			);
		}

		foreach ($shape->groups as $groupShape) {
			$entry = $topology['groups'][$groupShape->number] ?? null;
			if ($entry === null) {
				continue;
			}

			// a group the engine never fills holds the empty string: it is
			// numbered, it is optional, and nothing more is true of it
			$type = $groupShape->participation === Participation::Never()
				? new ConstantStringType('')
				: $this->stringTypeOf($groupShape->values, $groupShape->digitsOnly, $groupShape->nonFalsy, $groupShape->minLength);

			// the engine may leave a group unset for reasons the topology
			// cannot see - a (*ACCEPT) cut, a condition that never ran -
			// so the shape's own word decides optional presence too
			$group = new RegexCapturingGroup(
				$groupShape->number,
				$groupShape->name,
				$entry['alternation'] !== null ? $alternations[spl_object_id($entry['alternation'])] ?? null : null,
				$entry['inOptional'] || ($entry['alternation'] === null && $groupShape->participation === Participation::MayBeUnset()),
				$entry['parent'] !== null ? ($objects[$entry['parent']] ?? null) : null,
				$type,
			);
			// a nested group reaches its optionality through its parent, and
			// a capturing parent carries its own optionality — both must sit
			// in the same map the children look them up from
			$objects[spl_object_id($entry['node'])] = $group;

			$walkResult = $walkResult->addCapturingGroup($group);

			if ($entry['alternation'] !== null) {
				$alternationObject = $alternations[spl_object_id($entry['alternation'])] ?? null;
				if ($alternationObject !== null) {
					foreach ($entry['branches'] as $branch) {
						$alternationObject->pushGroup($branch, $group);
					}
				}
			}
		}

		// a non-capturing parent learns its capturing children only through
		// them: they exist above, the objects array already holds the link
		$whole = $shape->whole;
		$matchType = $this->containsEscapeK($pattern)
			? new StringType()
			: $this->stringTypeOf($whole->values, $whole->digitsOnly, $whole->nonFalsy, $whole->minLength);

		// the subject contains the match, whatever anchors hold: its accessories
		// — non-empty, non-falsy — carry over by containment, while nothing
		// sharper survives: the match's constants and its digits are the
		// match's own, and $ admits one final newline ("a\n" on /^(a|b|c)$/)
		$subjectNarrowType = $this->containsEscapeK($pattern)
			? new StringType()
			: $this->stringTypeOf(null, false, $whole->nonFalsy, $whole->minLength);

		return $walkResult
			->withSubjectBaseType($matchType)
			->withSubjectNarrowType($subjectNarrowType);
	}

	/**
	 * Where every group sits: its parent group node, the alternation above
	 * it, the branch it captures in. The shape numbers the groups the way
	 * the engine does; the topology walks the tree the same way, so a
	 * number finds its node.
	 *
	 * @param array{alternationNodes: list<array{node: NodeInterface, branches: int}>, groups: array<int, array{node: GroupNode, parent: ?int, alternation: ?NodeInterface, branches: list<int>, inOptional: bool}>, nonCapturing: array<int, array{parent: ?int, alternation: ?NodeInterface, inOptional: bool, resets: bool}>, nextNumber: int} $topology
	 * @return array{alternationNodes: list<array{node: NodeInterface, branches: int}>, groups: array<int, array{node: GroupNode, parent: ?int, alternation: ?NodeInterface, branches: list<int>, inOptional: bool}>, nonCapturing: array<int, array{parent: ?int, alternation: ?NodeInterface, inOptional: bool, resets: bool}>, nextNumber: int}
	 */
	private function collectTopology(
		NodeInterface $node,
		?int $parentId,
		?NodeInterface $alternation,
		int $branch,
		array $topology,
		bool $inOptionalQuantification = false,
	): array
	{
		if ($node instanceof AlternationNode) {
			$topology['alternationNodes'][] = ['node' => $node, 'branches' => count($node->alternatives)];
			foreach ($node->alternatives as $i => $alternative) {
				$topology = $this->collectTopology($alternative, $parentId, $node, $i, $topology);
			}

			return $topology;
		}

		if ($node instanceof DefineNode) {
			// the engine never fills what a (?(DEFINE)...) block defines: a
			// wrapper nothing runs marks every group below it optional,
			// through the parent chain the matcher reads
			$topology['nonCapturing'][spl_object_id($node)] = [
				'parent' => $parentId,
				'alternation' => $alternation,
				'inOptional' => true,
				'resets' => false,
			];

			return $this->collectTopology($node->content, spl_object_id($node), null, 0, $topology);
		}

		if ($node instanceof ConditionalNode) {
			// the engine numbers what a lookaround condition captures before
			// the branches' groups, and runs one branch at a time — the two
			// are as exclusive as an alternation's
			$topology['alternationNodes'][] = ['node' => $node, 'branches' => 2];
			$topology = $this->collectTopology($node->condition, $parentId, null, 0, $topology);
			$topology = $this->collectTopology($node->yes, $parentId, $node, 0, $topology);

			return $this->collectTopology($node->no, $parentId, $node, 1, $topology);
		}

		// an optional quantifier marks the one node it wraps, and only that
		// one: a group further below learns its optionality from its parent
		// chain, the way the matcher reads it
		if ($node instanceof QuantifierNode) {
			$bounds = QuantifierBounds::parse($node->quantifier);

			return $this->collectTopology(
				$node->node,
				$parentId,
				$alternation,
				$branch,
				$topology,
				$bounds !== null && $bounds->min === 0,
			);
		}

		if ($node instanceof GroupNode && $node->type === GroupType::BranchReset()) {
			// each branch numbers its groups from the same base, and the
			// next group follows the longest branch; the branches are as
			// exclusive as an alternation's, the matcher reads them so, and
			// the wrapper itself says the counter resets, the way a group
			// under it tells
			$topology['nonCapturing'][spl_object_id($node)] = [
				'parent' => $parentId,
				'alternation' => $alternation,
				'inOptional' => $inOptionalQuantification,
				'resets' => true,
			];
			$base = $topology['nextNumber'];
			$end = $base;
			$branchAlternation = $node->child instanceof AlternationNode ? $node->child : null;
			if ($branchAlternation !== null) {
				$topology['alternationNodes'][] = ['node' => $branchAlternation, 'branches' => count($branchAlternation->alternatives)];
				$branches = $branchAlternation->alternatives;
			} else {
				$branches = [$node->child];
			}
			foreach ($branches as $i => $branch) {
				$topology['nextNumber'] = $base;
				$topology = $this->collectTopology($branch, spl_object_id($node), $branchAlternation, $i, $topology, $inOptionalQuantification);
				$end = max($end, $topology['nextNumber']);
			}
			$topology['nextNumber'] = $end;

			return $topology;
		}

		if ($node instanceof GroupNode) {
			if ($node->type === GroupType::Capturing() || $node->type === GroupType::Named()) {
				$number = $topology['nextNumber'];
				$topology['nextNumber'] = $number + 1;
				$entry = $topology['groups'][$number] ?? null;
				$topology['groups'][$number] = [
					'node' => $node,
					'parent' => $parentId,
					'alternation' => $alternation,
					// a branch reset numbers one group per branch: the same
					// number captures in each of them
					'branches' => [...($entry['branches'] ?? []), $branch],
					'inOptional' => $inOptionalQuantification,
				];
				$parentId = spl_object_id($node);
			} else {
				$topology['nonCapturing'][spl_object_id($node)] = [
					'parent' => $parentId,
					'alternation' => $alternation,
					'inOptional' => $inOptionalQuantification,
					'resets' => false,
				];
				$parentId = spl_object_id($node);
			}
		}

		foreach ($node->getChildren() as $child) {
			$topology = $this->collectTopology($child, $parentId, $alternation, $branch, $topology);
		}

		return $topology;
	}

	/**
	 * @param list<string>|null $values
	 */
	private function stringTypeOf(?array $values, bool $digitsOnly, bool $nonFalsy, int $minLength): Type
	{
		if ($values !== null && $values !== []) {
			$types = [];
			foreach ($values as $value) {
				$types[] = new ConstantStringType($value);
			}

			return TypeCombinator::union(...$types);
		}

		if ($digitsOnly) {
			if ($nonFalsy) {
				return new IntersectionType([
					new StringType(),
					new AccessoryNumericStringType(),
					new AccessoryNonFalsyStringType(),
				]);
			}

			$result = new IntersectionType([new StringType(), new AccessoryNumericStringType()]);
			if ($minLength === 0) {
				return new UnionType([new ConstantStringType(''), $result]);
			}

			return $result;
		}

		if ($nonFalsy) {
			return new IntersectionType([new StringType(), new AccessoryNonFalsyStringType()]);
		}

		if ($minLength >= 1) {
			return new IntersectionType([new StringType(), new AccessoryNonEmptyStringType()]);
		}

		return new StringType();
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
