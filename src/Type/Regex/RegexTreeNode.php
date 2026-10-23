<?php declare(strict_types = 1);

namespace PHPStan\Type\Regex;

use function count;

/**
 * The tree the regex shape pipeline walks: a plain value node carrying an
 * id (a grammar rule like #capturing, or "token"), an optional value array
 * (token name and raw value), and children. It replaces the Hoa compiler's
 * node class the grammar used to produce, keeping the surface the walkers
 * were written against.
 */
final class RegexTreeNode
{

	/**
	 * @param string $id a rule name like #capturing, or "token"
	 * @param array{token?: string, value?: string, namespace?: string}|null $value
	 * @param list<self> $children
	 */
	public function __construct(
		private readonly string $id,
		private readonly ?array $value = null,
		private array $children = [],
	) {
	}

	public function getId(): string
	{
		return $this->id;
	}

	/**
	 * @return array{token?: string, value?: string, namespace?: string}|null
	 */
	public function getValue(): ?array
	{
		return $this->value;
	}

	public function getValueToken(): ?string
	{
		return $this->value['token'] ?? null;
	}

	public function getValueValue(): ?string
	{
		return $this->value['value'] ?? null;
	}

	/**
	 * @return list<self>
	 */
	public function getChildren(): array
	{
		return $this->children;
	}

	/**
	 * @param list<self> $children
	 */
	public function setChildren(array $children): void
	{
		$this->children = $children;
	}

	public function getChild(int $i): ?self
	{
		return $this->children[$i] ?? null;
	}

	public function getChildrenNumber(): int
	{
		return count($this->children);
	}

}
