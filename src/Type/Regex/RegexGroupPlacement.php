<?php declare(strict_types = 1);

namespace PHPStan\Type\Regex;

use PHPRegex\Parser\Node\NodeInterface;

/**
 * Where a group sits in the pattern: its parent group, the alternation right
 * above it and its branch in that alternation. A non-capturing group has no number.
 */
final class RegexGroupPlacement
{

	public function __construct(
		private int $nodeId,
		private ?int $number,
		private ?int $parentNodeId,
		private ?NodeInterface $alternation,
		private int $branch,
		private bool $inOptionalQuantification,
		private bool $resetsGroupCounter,
	)
	{
	}

	public function getNodeId(): int
	{
		return $this->nodeId;
	}

	public function getNumber(): ?int
	{
		return $this->number;
	}

	public function getParentNodeId(): ?int
	{
		return $this->parentNodeId;
	}

	public function getAlternation(): ?NodeInterface
	{
		return $this->alternation;
	}

	public function getBranch(): int
	{
		return $this->branch;
	}

	public function inOptionalQuantification(): bool
	{
		return $this->inOptionalQuantification;
	}

	public function resetsGroupCounter(): bool
	{
		return $this->resetsGroupCounter;
	}

}
