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
	 * @param Type|null $subjectNarrowType what the subject itself narrows to: null while
	 *            the pattern anchors no end — a subject only ever contains the match —
	 *            for an anchored one the accessories the match still leaves open, for a
	 *            subject is never the match: $ admits one final newline
	 */
	public function __construct(
		private int $alternationId,
		private array $capturingGroups,
		private array $markVerbs,
		private Type $subjectBaseType,
		private ?Type $subjectNarrowType = null,
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
		);
	}

	public function withSubjectBaseType(Type $subjectBaseType): self
	{
		return new self(
			$this->alternationId,
			$this->capturingGroups,
			$this->markVerbs,
			$subjectBaseType,
		);
	}

	public function withSubjectNarrowType(?Type $subjectNarrowType): self
	{
		return new self(
			$this->alternationId,
			$this->capturingGroups,
			$this->markVerbs,
			$this->subjectBaseType,
			$subjectNarrowType,
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

	/**
	 * The subject narrowed by a match on the pattern: null while the pattern
	 * anchors no end, for an anchored one the accessories alone.
	 */
	public function getSubjectNarrowType(): ?Type
	{
		return $this->subjectNarrowType;
	}

}
