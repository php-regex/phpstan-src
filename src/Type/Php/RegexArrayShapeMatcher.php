<?php declare(strict_types = 1);

namespace PHPStan\Type\Php;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Php\PhpVersion;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Accessory\AccessoryArrayListType;
use PHPStan\Type\Accessory\NonEmptyArrayType;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\IntegerRangeType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\IntersectionType;
use PHPStan\Type\NullType;
use PHPStan\Type\Regex\RegexCapturingGroup;
use PHPStan\Type\Regex\RegexExpressionHelper;
use PHPStan\Type\Regex\RegexGroupList;
use PHPStan\Type\Regex\RegexGroupParser;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use function array_reverse;
use function count;
use function in_array;
use function is_string;
use const PREG_OFFSET_CAPTURE;
use const PREG_PATTERN_ORDER;
use const PREG_SET_ORDER;
use const PREG_UNMATCHED_AS_NULL;

/**
 * @api
 */
#[AutowiredService]
final class RegexArrayShapeMatcher
{

	/**
	 * Pass this into $flagsType as well if the library supports emulating PREG_UNMATCHED_AS_NULL on PHP 7.2 and 7.3
	 */
	public const PREG_UNMATCHED_AS_NULL_ON_72_73 = 2048;

	public function __construct(
		private RegexGroupParser $regexGroupParser,
		private RegexExpressionHelper $regexExpressionHelper,
		private PhpVersion $phpVersion,
	)
	{
	}

	public function matchAllExpr(Expr $patternExpr, ?Type $flagsType, TrinaryLogic $wasMatched, Scope $scope): ?Type
	{
		return $this->matchPatternType($this->getPatternType($patternExpr, $scope), $flagsType, $wasMatched, true);
	}

	/**
	 * Whether a preg_match() call starts its match from an offset: the match is then one of a scan,
	 * and a zero-width first branch like ^ no longer wins it.
	 */
	public function matchesFromOffset(FuncCall $call, Scope $scope): bool
	{
		$offsetArg = $call->getArgs()[4] ?? null;
		if ($offsetArg === null) {
			return false;
		}

		return !(new ConstantIntegerType(0))->isSuperTypeOf($scope->getType($offsetArg->value))->yes();
	}

	/**
	 * @param bool $inScan the match is one of a scan, e.g. a preg_replace_callback() callback or a preg_match() from an offset
	 */
	public function matchExpr(Expr $patternExpr, ?Type $flagsType, TrinaryLogic $wasMatched, Scope $scope, bool $inScan = false): ?Type
	{
		return $this->matchPatternType($this->getPatternType($patternExpr, $scope), $flagsType, $wasMatched, false, $inScan);
	}

	public function matchSubjectExpr(Expr $patternExpr, Scope $scope): ?Type
	{
		$patternType = $this->getPatternType($patternExpr, $scope);
		$constantStrings = $patternType->getConstantStrings();
		if (count($constantStrings) === 0) {
			return null;
		}

		$subjectTypes = [];
		foreach ($constantStrings as $constantString) {
			$astWalkResult = $this->regexGroupParser->parseGroups($constantString->getValue());
			if ($astWalkResult === null) {
				return null;
			}

			$subjectTypes[] = $astWalkResult->getSubjectNarrowType();
		}

		return TypeCombinator::union(...$subjectTypes);
	}

	private function matchPatternType(Type $patternType, ?Type $flagsType, TrinaryLogic $wasMatched, bool $matchesAll, bool $inScan = false): ?Type
	{
		if ($wasMatched->no()) {
			return ConstantArrayTypeBuilder::createEmpty()->getArray();
		}

		$constantStrings = $patternType->getConstantStrings();
		if (count($constantStrings) === 0) {
			return null;
		}

		$flags = null;
		if ($flagsType !== null) {
			if (!$flagsType instanceof ConstantIntegerType) {
				return null;
			}

			/** @var int-mask<PREG_OFFSET_CAPTURE | PREG_PATTERN_ORDER | PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL | self::PREG_UNMATCHED_AS_NULL_ON_72_73> $flags */
			$flags = $flagsType->getValue() & (PREG_OFFSET_CAPTURE | PREG_PATTERN_ORDER | PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL | self::PREG_UNMATCHED_AS_NULL_ON_72_73);

			// some other unsupported/unexpected flag was passed in
			if ($flags !== $flagsType->getValue()) {
				return null;
			}
		}

		$matchedTypes = [];
		foreach ($constantStrings as $constantString) {
			$matched = $this->matchRegex($constantString->getValue(), $flags, $wasMatched, $matchesAll, $inScan);
			if ($matched === null) {
				return null;
			}

			$matchedTypes[] = $matched;
		}

		if (count($matchedTypes) === 1) {
			return $matchedTypes[0];
		}

		return TypeCombinator::union(...$matchedTypes);
	}

	/**
	 * @param int-mask<PREG_OFFSET_CAPTURE|PREG_PATTERN_ORDER|PREG_SET_ORDER|PREG_UNMATCHED_AS_NULL|self::PREG_UNMATCHED_AS_NULL_ON_72_73>|null $flags
	 */
	private function matchRegex(string $regex, ?int $flags, TrinaryLogic $wasMatched, bool $matchesAll, bool $inScan): ?Type
	{
		$astWalkResult = $this->regexGroupParser->parseGroups($regex);
		if ($astWalkResult === null) {
			// regex could not be parsed by RegexParser
			return null;
		}
		$groupList = $this->resolveNeverMatchedGroups($astWalkResult->getCapturingGroups(), $flags ?? 0, $matchesAll);
		$markVerbs = $astWalkResult->getMarkVerbs();
		$subjectBaseType = new StringType();
		if ($wasMatched->yes()) {
			$subjectBaseType = $inScan ? $astWalkResult->getScanBaseType() : $astWalkResult->getSubjectBaseType();
		}

		$regexGroupList = new RegexGroupList($groupList);
		$trailingOptionals = $regexGroupList->countTrailingOptionals();
		$onlyOptionalTopLevelGroup = $regexGroupList->getOnlyOptionalTopLevelGroup();
		$onlyTopLevelAlternation = $regexGroupList->getOnlyTopLevelAlternation();
		$flags ??= 0;

		if (
			!$matchesAll
			&& $wasMatched->yes()
			&& $onlyOptionalTopLevelGroup !== null
		) {
			// if only one top level capturing optional group exists
			// we build a more precise tagged union of a empty-match and a match with the group
			$regexGroupList = $regexGroupList->forceGroupNonOptional($onlyOptionalTopLevelGroup);

			$combiType = $this->buildArrayType(
				$subjectBaseType,
				$regexGroupList,
				$wasMatched,
				$trailingOptionals,
				$flags,
				$markVerbs,
				$matchesAll,
			);

			if (!$this->containsUnmatchedAsNull($flags, $matchesAll)) {
				// positive match has a subject but not any capturing group
				$builder = ConstantArrayTypeBuilder::createEmpty();
				$builder->setOffsetValueType(new ConstantIntegerType(0), $this->createSubjectValueType($subjectBaseType, $flags, $matchesAll, $wasMatched));

				$combiType = TypeCombinator::union(
					$builder->getArray(),
					$combiType,
				);
			}

			return $combiType;
		} elseif (
			!$matchesAll
			&& $onlyOptionalTopLevelGroup === null
			&& $onlyTopLevelAlternation !== null
			&& !$wasMatched->no()
		) {
			// if only a single top level alternation exist built a more precise tagged union

			$combiTypes = [];
			$isOptionalAlternation = false;
			foreach ($onlyTopLevelAlternation->getGroupCombinations() as $groupCombo) {
				$comboList = new RegexGroupList($groupList);

				$beforeCurrentCombo = true;
				foreach ($comboList as $group) {
					if (in_array($group->getId(), $groupCombo, true)) {
						$isOptionalAlternation = $group->inOptionalAlternation();
						$comboList = $comboList->forceGroupAlternationBranchMatched($group);
						$beforeCurrentCombo = false;
					} elseif ($beforeCurrentCombo && !$group->resetsGroupCounter()) {
						$comboList = $comboList->forceGroupTypeAndNonOptional(
							$group,
							$this->getUnmatchedGroupType($flags),
						);
					} elseif (
						$group->getAlternationId() === $onlyTopLevelAlternation->getId()
						&& !$this->containsUnmatchedAsNull($flags, $matchesAll)
					) {
						$comboList = $comboList->removeGroup($group);
					}
				}

				$combiType = $this->buildArrayType(
					$subjectBaseType,
					$comboList,
					$wasMatched,
					$trailingOptionals,
					$flags,
					$markVerbs,
					$matchesAll,
				);

				$combiTypes[] = $combiType;
			}

			if (
				!$this->containsUnmatchedAsNull($flags, $matchesAll)
				&& (
					$onlyTopLevelAlternation->getAlternationsCount() !== count($onlyTopLevelAlternation->getGroupCombinations())
					|| $isOptionalAlternation
				)
			) {
				// positive match has a subject but not any capturing group
				$builder = ConstantArrayTypeBuilder::createEmpty();
				$builder->setOffsetValueType(new ConstantIntegerType(0), $this->createSubjectValueType($subjectBaseType, $flags, $matchesAll, $wasMatched));

				$combiTypes[] = $builder->getArray();
			}

			return TypeCombinator::union(...$combiTypes);
		}

		if (!$matchesAll && $astWalkResult->getCases() !== []) {
			// the union of the cases the pattern splits into, e.g. an optional group with nested groups
			// is either set with all of them or skipped with all of them; a failed match returned above
			$combiTypes = [];
			foreach ($astWalkResult->getCases() as $participations) {
				$caseGroups = [];
				foreach ($astWalkResult->getCapturingGroups() as $id => $group) {
					$set = $participations[$group->getId()] ?? null;
					if ($set === true) {
						$group = $group->forceNonOptional();
					} elseif ($set === false) {
						$group = $group->forceNeverMatched();
					}
					$caseGroups[$id] = $group;
				}
				$caseList = new RegexGroupList($this->resolveNeverMatchedGroups($caseGroups, $flags, $matchesAll));

				$combiTypes[] = $this->buildArrayType(
					$subjectBaseType,
					$caseList,
					$wasMatched,
					$caseList->countTrailingOptionals(),
					$flags,
					$markVerbs,
					$matchesAll,
				);
			}

			return TypeCombinator::union(...$combiTypes);
		}

		// the general case, which should work in all cases but does not yield the most
		// precise result possible in some cases
		return $this->buildArrayType(
			$subjectBaseType,
			$regexGroupList,
			$wasMatched,
			$trailingOptionals,
			$flags,
			$markVerbs,
			$matchesAll,
		);
	}

	/**
	 * @param list<string> $markVerbs
	 */
	private function buildArrayType(
		Type $subjectBaseType,
		RegexGroupList $captureGroups,
		TrinaryLogic $wasMatched,
		int $trailingOptionals,
		int $flags,
		array $markVerbs,
		bool $matchesAll,
	): Type
	{
		$forceList = count($markVerbs) === 0;
		$builder = ConstantArrayTypeBuilder::createEmpty();

		// first item in matches contains the overall match.
		$builder->setOffsetValueType(
			$this->getKeyType(0),
			$this->createSubjectValueType($subjectBaseType, $flags, $matchesAll, $wasMatched),
			$this->isSubjectOptional($wasMatched, $matchesAll),
		);

		$countGroups = count($captureGroups);
		$composed = [];
		$names = [];
		$i = 0;
		foreach ($captureGroups as $captureGroup) {
			$isTrailingOptional = $i >= $countGroups - $trailingOptionals;
			$isLastGroup = $i === $countGroups - 1;
			$groupValueType = $this->createGroupValueType($captureGroup, $wasMatched, $flags, $isTrailingOptional, $isLastGroup, $matchesAll);
			$optional = $this->isGroupOptional($captureGroup, $wasMatched, $flags, $isTrailingOptional, $matchesAll);
			$composed[$i] = [$captureGroup, $groupValueType, $optional];

			if ($captureGroup->isNamed()) {
				$forceList = false;
				// a group that may be unset writes the name only when it is set
				$setValueType = $captureGroup->isOptional() && !$captureGroup->isNeverMatched()
					? $this->createGroupValueType($captureGroup->forceNonOptional(), $wasMatched, $flags, false, $isLastGroup, $matchesAll)
					: $groupValueType;
				$names[$captureGroup->getName()][] = [$captureGroup, $groupValueType, $setValueType, $optional];
			}

			$i++;
		}
		$resolvedNames = [];
		foreach ($names as $name => $groups) {
			$resolvedNames[$name] = $this->resolveNamedKey($groups, $matchesAll && $this->containsPatternOrder($flags));
		}
		foreach ($composed as $i => [$captureGroup, $groupValueType, $optional]) {
			if ($captureGroup->isNamed()) {
				[$nameType, $nameOptional] = $resolvedNames[$captureGroup->getName()];
				$builder->setOffsetValueType(
					$this->getKeyType($captureGroup->getName()),
					$nameType,
					$nameOptional,
				);
			}

			$builder->setOffsetValueType(
				$this->getKeyType($i + 1),
				$groupValueType,
				$optional,
			);
		}

		if (count($markVerbs) > 0) {
			$markTypes = [];
			foreach ($markVerbs as $mark) {
				$markTypes[] = new ConstantStringType($mark);
			}
			$markType = TypeCombinator::union(...$markTypes);
			if ($matchesAll && $this->containsPatternOrder($flags)) {
				// marks are keyed by the index of the match that set them, matches without a mark are left out
				$markType = new IntersectionType([
					new ArrayType(IntegerRangeType::createAllGreaterThanOrEqualTo(0), $markType),
					new NonEmptyArrayType(),
				]);
			}

			$builder->setOffsetValueType(
				$this->getKeyType('MARK'),
				$markType,
				true,
			);
		}

		if ($matchesAll && $this->containsSetOrder($flags)) {
			$accessoryTypes = [new ArrayType(IntegerRangeType::createAllGreaterThanOrEqualTo(0), $builder->getArray()), new AccessoryArrayListType()];
			if ($wasMatched->yes()) {
				$accessoryTypes[] = new NonEmptyArrayType();
			}

			return new IntersectionType($accessoryTypes);
		}

		if ($forceList) {
			return TypeCombinator::intersect($builder->getArray(), new AccessoryArrayListType());
		}

		return $builder->getArray();
	}

	/**
	 * Several groups can share a name (J modifier, or a branch reset). PHP writes the name
	 * for each group that is set, in order, and once with the unmatched value when none is:
	 * the key holds the value of the last group set, and is there whenever one of the groups
	 * has its own key. In /(?:(?<a>x)|(?<b>y))(?:(?<a>z))?/J on 'y', group 1 is unset but not
	 * trailing, so 'a' is '' until group 3 writes it.
	 *
	 * With PREG_PATTERN_ORDER the name holds the list of the last group of the name, set or not.
	 *
	 * @param list<array{RegexCapturingGroup, Type, Type, bool}> $groups each group of the name, its value type, its value type when it is set, and whether its key is optional, in order
	 * @return array{Type, bool}
	 */
	private function resolveNamedKey(array $groups, bool $lastGroupWins): array
	{
		if ($lastGroupWins) {
			[, $type, , $optional] = $groups[count($groups) - 1];

			return [$type, $optional];
		}

		// a group that is always set hides every group before it, set or not
		$lastAlwaysSet = null;
		foreach ($groups as $i => [$group]) {
			if ($group->isNeverMatched() || $group->isOptional()) {
				continue;
			}

			$lastAlwaysSet = $i;
		}

		$types = [];
		$keyOptional = true;
		foreach ($groups as $i => [$group, $type, $setType, $optional]) {
			$keyOptional = $keyOptional && $optional;
			if ($lastAlwaysSet === null) {
				// the first group writes the unmatched value, a later one its own when set
				$types[] = $type;
				continue;
			}

			if ($i < $lastAlwaysSet || $group->isNeverMatched()) {
				continue;
			}

			// after the last group always set, only a group that is set writes the name again
			$types[] = $i === $lastAlwaysSet ? $type : $setType;
		}

		return [TypeCombinator::union(...$types), $keyOptional];
	}

	/**
	 * A group the engine never sets holds the unmatched value, and has no key
	 * when no group after it can be set: /(?(DEFINE)(a))b/ writes ['b'].
	 *
	 * @param array<int, RegexCapturingGroup> $groupList
	 * @return array<int, RegexCapturingGroup>
	 */
	private function resolveNeverMatchedGroups(array $groupList, int $flags, bool $matchesAll): array
	{
		$keyAlwaysWritten = $this->containsUnmatchedAsNull($flags, $matchesAll)
			|| ($matchesAll && $this->containsPatternOrder($flags));

		$trailing = true;
		foreach (array_reverse($groupList, true) as $id => $group) {
			if (!$group->isNeverMatched()) {
				$trailing = false;
				continue;
			}

			if ($trailing && !$keyAlwaysWritten) {
				unset($groupList[$id]);
				continue;
			}

			$groupList[$id] = $group->forceType($this->getUnmatchedGroupType($flags));
		}

		return $groupList;
	}

	private function isSubjectOptional(TrinaryLogic $wasMatched, bool $matchesAll): bool
	{
		if ($matchesAll) {
			return false;
		}

		return !$wasMatched->yes();
	}

	/**
	 * @param Type $baseType A string type (or string variant) representing the subject of the match
	 */
	private function createSubjectValueType(Type $baseType, int $flags, bool $matchesAll, TrinaryLogic $wasMatched): Type
	{
		// $matches[0] is always set on a match, its offset is never -1
		$subjectValueType = $this->wrapOffset($baseType, $flags, false);

		if ($matchesAll) {
			$subjectValueType = $this->wrapOffset(new StringType(), $flags, false);

			if ($this->containsPatternOrder($flags)) {
				$accessoryTypes = [
					new ArrayType(new IntegerType(), $subjectValueType),
					new AccessoryArrayListType(),
				];
				if ($wasMatched->yes()) {
					$accessoryTypes[] = new NonEmptyArrayType();
				}

				$subjectValueType = new IntersectionType($accessoryTypes);
			}
		}

		return $subjectValueType;
	}

	private function isGroupOptional(RegexCapturingGroup $captureGroup, TrinaryLogic $wasMatched, int $flags, bool $isTrailingOptional, bool $matchesAll): bool
	{
		if ($matchesAll) {
			if ($isTrailingOptional && !$this->containsUnmatchedAsNull($flags, $matchesAll) && $this->containsSetOrder($flags)) {
				return true;
			}

			return false;
		}

		if (!$wasMatched->yes()) {
			$optional = true;
		} else {
			if (!$isTrailingOptional) {
				$optional = false;
			} elseif ($this->containsUnmatchedAsNull($flags, $matchesAll)) {
				$optional = false;
			} else {
				$optional = $captureGroup->isOptional();
			}
		}

		return $optional;
	}

	private function createGroupValueType(RegexCapturingGroup $captureGroup, TrinaryLogic $wasMatched, int $flags, bool $isTrailingOptional, bool $isLastGroup, bool $matchesAll): Type
	{
		$unmatchedAsNull = $this->containsUnmatchedAsNull($flags, $matchesAll);

		if ($matchesAll) {
			if (
				(
					!$this->containsSetOrder($flags)
					&& !$this->containsUnmatchedAsNull($flags, $matchesAll)
					&& $captureGroup->isOptional()
				)
				||
				(
					$this->containsSetOrder($flags)
					&& !$this->containsUnmatchedAsNull($flags, $matchesAll)
					&& $captureGroup->isOptional()
					// an unmatched group followed by a matched one is reported as ''
					&& !$isLastGroup
				)
			) {
				$valueType = TypeCombinator::union($captureGroup->getType(), new ConstantStringType(''));
				$offsetMayBeMinusOne = true;
			} else {
				$valueType = $this->addUnmatchedNull($captureGroup->getType(), $flags, $matchesAll);
				$offsetMayBeMinusOne = $this->canBeUnmatchedAndPresent($captureGroup, $flags, $matchesAll);
			}

			if (!$isTrailingOptional && $this->containsUnmatchedAsNull($flags, $matchesAll) && !$captureGroup->isOptional()) {
				$valueType = TypeCombinator::removeNull($valueType);
			}

			$groupValueType = $this->wrapOffset($valueType, $flags, $unmatchedAsNull ? TypeCombinator::containsNull($valueType) : $offsetMayBeMinusOne);

			if ($this->containsPatternOrder($flags)) {
				$accessoryTypes = [new ArrayType(IntegerRangeType::createAllGreaterThanOrEqualTo(0), $groupValueType), new AccessoryArrayListType()];
				if ($wasMatched->yes()) {
					$accessoryTypes[] = new NonEmptyArrayType();
				}

				$groupValueType = new IntersectionType($accessoryTypes);
			}

			return $groupValueType;
		}

		if (!$isLastGroup && !$this->containsUnmatchedAsNull($flags, $matchesAll) && $captureGroup->isOptional()) {
			$valueType = TypeCombinator::union($captureGroup->getType(), $this->getUnmatchedGroupType($flags));
			$offsetMayBeMinusOne = true;
		} else {
			$valueType = $this->addUnmatchedNull($captureGroup->getType(), $flags, $matchesAll);
			$offsetMayBeMinusOne = $this->canBeUnmatchedAndPresent($captureGroup, $flags, $matchesAll);
		}

		if ($wasMatched->yes()) {
			if (!$isTrailingOptional && $this->containsUnmatchedAsNull($flags, $matchesAll) && !$captureGroup->isOptional()) {
				$valueType = TypeCombinator::removeNull($valueType);
			}
		}

		// with PREG_UNMATCHED_AS_NULL an unmatched group is [null, -1]
		return $this->wrapOffset($valueType, $flags, $unmatchedAsNull ? TypeCombinator::containsNull($valueType) : $offsetMayBeMinusOne);
	}

	private function containsOffsetCapture(int $flags): bool
	{
		return ($flags & PREG_OFFSET_CAPTURE) !== 0;
	}

	private function containsPatternOrder(int $flags): bool
	{
		// If no order flag is given, PREG_PATTERN_ORDER is assumed.
		return !$this->containsSetOrder($flags);
	}

	private function containsSetOrder(int $flags): bool
	{
		return ($flags & PREG_SET_ORDER) !== 0;
	}

	private function containsUnmatchedAsNull(int $flags, bool $matchesAll): bool
	{
		if ($matchesAll) {
			// preg_match_all() with PREG_UNMATCHED_AS_NULL works consistently across php-versions
			// https://3v4l.org/tKmPn
			return ($flags & PREG_UNMATCHED_AS_NULL) !== 0;
		}

		return ($flags & PREG_UNMATCHED_AS_NULL) !== 0 && (($flags & self::PREG_UNMATCHED_AS_NULL_ON_72_73) !== 0 || $this->phpVersion->supportsPregUnmatchedAsNull());
	}

	/**
	 * Type of an unmatched group followed by a matched one.
	 *
	 * PHP 7.2 and 7.3 already report it as null with PREG_UNMATCHED_AS_NULL,
	 * only the trailing unmatched groups are omitted there.
	 */
	private function getUnmatchedGroupType(int $flags): Type
	{
		if (($flags & PREG_UNMATCHED_AS_NULL) !== 0) {
			return new NullType();
		}

		return new ConstantStringType('');
	}

	private function getKeyType(int|string $key): Type
	{
		if (is_string($key)) {
			return new ConstantStringType($key);
		}

		return new ConstantIntegerType($key);
	}

	private function canBeUnmatchedAndPresent(RegexCapturingGroup $captureGroup, int $flags, bool $matchesAll): bool
	{
		// a group the engine never sets, or one in a branch the arm does not take, keeps its key
		// with the unmatched value and -1 as offset: its type is that value and nothing else
		if ($captureGroup->isNeverMatched() || $captureGroup->getType()->equals($this->getUnmatchedGroupType($flags))) {
			return true;
		}

		return $this->containsUnmatchedAsNull($flags, $matchesAll) && $captureGroup->isOptional();
	}

	private function addUnmatchedNull(Type $valueType, int $flags, bool $matchesAll): Type
	{
		if ($this->containsUnmatchedAsNull($flags, $matchesAll)) {
			return TypeCombinator::addNull($valueType);
		}

		return $valueType;
	}

	/**
	 * With PREG_OFFSET_CAPTURE, the value and its offset; an unmatched group returns -1 as offset.
	 */
	private function wrapOffset(Type $valueType, int $flags, bool $offsetMayBeMinusOne): Type
	{
		if (!$this->containsOffsetCapture($flags)) {
			return $valueType;
		}

		$builder = ConstantArrayTypeBuilder::createEmpty();
		$builder->setOffsetValueType(new ConstantIntegerType(0), $valueType);
		$builder->setOffsetValueType(
			new ConstantIntegerType(1),
			$offsetMayBeMinusOne ? IntegerRangeType::fromInterval(-1, null) : IntegerRangeType::fromInterval(0, null),
		);

		return $builder->getArray();
	}

	private function getPatternType(Expr $patternExpr, Scope $scope): Type
	{
		if ($patternExpr instanceof Expr\BinaryOp\Concat) {
			return $this->regexExpressionHelper->resolvePatternConcat($patternExpr, $scope);
		}

		return $scope->getType($patternExpr);
	}

}
