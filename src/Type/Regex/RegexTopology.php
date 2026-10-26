<?php declare(strict_types = 1);

namespace PHPStan\Type\Regex;

use PHPRegex\Parser\Node\NodeInterface;
use function spl_object_id;

/**
 * The groups of a pattern in tree order, numbered as PCRE numbers them,
 * and the alternations they sit in.
 */
final class RegexTopology
{

	/** @var list<NodeInterface> */
	private array $alternations = [];

	/** @var array<int, int> branch count by alternation node id */
	private array $branchCounts = [];

	/** @var list<RegexGroupPlacement> */
	private array $placements = [];

	private int $nextNumber = 1;

	public function addAlternation(NodeInterface $node, int $branches): void
	{
		$this->alternations[] = $node;
		$this->branchCounts[spl_object_id($node)] = $branches;
	}

	public function addPlacement(RegexGroupPlacement $placement): void
	{
		$this->placements[] = $placement;
	}

	public function takeNumber(): int
	{
		return $this->nextNumber++;
	}

	public function getNextNumber(): int
	{
		return $this->nextNumber;
	}

	public function setNextNumber(int $number): void
	{
		$this->nextNumber = $number;
	}

	/**
	 * @return list<NodeInterface>
	 */
	public function getAlternations(): array
	{
		return $this->alternations;
	}

	public function getBranchCount(NodeInterface $alternation): int
	{
		return $this->branchCounts[spl_object_id($alternation)];
	}

	/**
	 * @return list<RegexGroupPlacement>
	 */
	public function getPlacements(): array
	{
		return $this->placements;
	}

	/**
	 * The placements of each capturing group number, in tree order: several
	 * in a branch reset, where one number is captured in several branches.
	 *
	 * @return array<int, non-empty-list<RegexGroupPlacement>>
	 */
	public function getPlacementsByNumber(): array
	{
		$byNumber = [];
		foreach ($this->placements as $placement) {
			if ($placement->getNumber() === null) {
				continue;
			}

			$byNumber[$placement->getNumber()][] = $placement;
		}

		return $byNumber;
	}

}
