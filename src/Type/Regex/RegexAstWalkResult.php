<?php declare(strict_types = 1);

namespace PHPStan\Type\Regex;

use PHPStan\Type\StringType;
use PHPStan\Type\Type;

/** @immutable */
final class RegexAstWalkResult
{

	/**
	 * @param array<int, RegexCapturingGroup> $capturingGroups
	 * @param list<string> $markVerbs
	 * @param Type $subjectBaseType type of the whole match, $matches[0]
	 * @param Type $subjectNarrowType type the subject is narrowed to when the pattern matches
	 * @param Type|null $scanBaseType type of the whole match when the match is one of a scan,
	 *                                 e.g. in a preg_replace_callback() callback, null when it is the same
	 * @param list<array<int, bool>> $cases cases whose union covers every match: the groups each case
	 *                                       sets (true) or never sets (false), a group left out may be unset
	 */
	public function __construct(
		private int $alternationId,
		private array $capturingGroups,
		private array $markVerbs,
		private Type $subjectBaseType,
		private Type $subjectNarrowType,
		private ?Type $scanBaseType = null,
		private array $cases = [],
	)
	{
	}

	public static function createEmpty(): self
	{
		return new self(
			-1,
			[],
			[],
			new StringType(),
			new StringType(),
		);
	}

	public function nextAlternationId(): self
	{
		return new self(
			$this->alternationId + 1,
			$this->capturingGroups,
			$this->markVerbs,
			$this->subjectBaseType,
			$this->subjectNarrowType,
			$this->scanBaseType,
			$this->cases,
		);
	}

	public function addCapturingGroup(RegexCapturingGroup $group): self
	{
		$capturingGroups = $this->capturingGroups;
		$capturingGroups[$group->getId()] = $group;

		return new self(
			$this->alternationId,
			$capturingGroups,
			$this->markVerbs,
			$this->subjectBaseType,
			$this->subjectNarrowType,
			$this->scanBaseType,
			$this->cases,
		);
	}

	public function markVerb(string $markVerb): self
	{
		$verbs = $this->markVerbs;
		$verbs[] = $markVerb;

		return new self(
			$this->alternationId,
			$this->capturingGroups,
			$verbs,
			$this->subjectBaseType,
			$this->subjectNarrowType,
			$this->scanBaseType,
			$this->cases,
		);
	}

	public function withSubjectBaseType(Type $subjectBaseType): self
	{
		return new self(
			$this->alternationId,
			$this->capturingGroups,
			$this->markVerbs,
			$subjectBaseType,
			$this->subjectNarrowType,
			$this->scanBaseType,
			$this->cases,
		);
	}

	public function withSubjectNarrowType(Type $subjectNarrowType): self
	{
		return new self(
			$this->alternationId,
			$this->capturingGroups,
			$this->markVerbs,
			$this->subjectBaseType,
			$subjectNarrowType,
			$this->scanBaseType,
			$this->cases,
		);
	}

	public function getAlternationId(): int
	{
		return $this->alternationId;
	}

	/**
	 * @return array<int, RegexCapturingGroup>
	 */
	public function getCapturingGroups(): array
	{
		return $this->capturingGroups;
	}

	/**
	 * @return list<string>
	 */
	public function getMarkVerbs(): array
	{
		return $this->markVerbs;
	}

	public function getSubjectBaseType(): Type
	{
		return $this->subjectBaseType;
	}

	public function withScanBaseType(?Type $scanBaseType): self
	{
		return new self(
			$this->alternationId,
			$this->capturingGroups,
			$this->markVerbs,
			$this->subjectBaseType,
			$this->subjectNarrowType,
			$scanBaseType,
			$this->cases,
		);
	}

	public function getScanBaseType(): Type
	{
		return $this->scanBaseType ?? $this->subjectBaseType;
	}

	public function getSubjectNarrowType(): Type
	{
		return $this->subjectNarrowType;
	}

	/**
	 * @param list<array<int, bool>> $cases
	 */
	public function withCases(array $cases): self
	{
		return new self(
			$this->alternationId,
			$this->capturingGroups,
			$this->markVerbs,
			$this->subjectBaseType,
			$this->subjectNarrowType,
			$this->scanBaseType,
			$cases,
		);
	}

	/**
	 * @return list<array<int, bool>>
	 */
	public function getCases(): array
	{
		return $this->cases;
	}

}
