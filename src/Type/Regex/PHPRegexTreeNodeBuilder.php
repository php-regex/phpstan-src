<?php declare(strict_types = 1);

namespace PHPStan\Type\Regex;

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
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\Node\VersionConditionNode;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Php\PcreVersion;
use PHPStan\Php\PhpVersion;
use function array_merge;
use function count;
use function ctype_digit;
use function mb_chr;
use function mb_str_split;
use function str_starts_with;
use function substr;

/**
 * Builds the RegexTreeNode trees the RegexGroupParser walkers consume, from the
 * PHPRegex parser, so the shape pipeline keeps its exact vocabulary while
 * reading PCRE the current engine reads.
 *
 * The read is judged for the analysed project: a padded \x{ 41 } escape is
 * the letter A on the engines PHP 8.x ships with and the letter x then text
 * on PHP 7.4's, and the shapes follow that verdict. The judged engine is
 * the one the analysed PHP bundles, narrowed below it when the config or
 * composer.json names an older one — a pin never raises the judged engine
 * above what that PHP can run.
 */
#[AutowiredService]
final class PHPRegexTreeNodeBuilder
{

	private ?RegexParser $parser = null;

	public function __construct(
		private PhpVersion $phpVersion,
		private PcreVersion $pcreVersion,
	) {
	}

	/**
	 * @param string $rawRegex  the pattern body, delimiters and modifiers stripped
	 * @param string $modifiers the pattern modifiers as RegexGroupParser read them
	 * @param string $delimiter the opening delimiter of the pattern as written
	 *
	 * @throws RegexException when the pattern body cannot be parsed
	 */
	public function parse(string $rawRegex, string $modifiers, string $delimiter): RegexTreeNode
	{
		$this->parser ??= RegexParser::create($this->targetOptions());

		$ast = $this->parser->parsePattern($rawRegex, $modifiers, $delimiter);

		$body = self::convertBody($ast->pattern);

		return new RegexTreeNode('#expression', null, $body === null ? [] : [$body]);
	}

	/**
	 * @return array<string, int|string>
	 */
	private function targetOptions(): array
	{
		$phpVersionId = $this->phpVersion->getVersionId();
		$bundled = PcreTarget::bundledWith($phpVersionId);

		$options = ['php_version' => $phpVersionId];
		if (!$this->pcreVersion->atLeast($bundled->pcreVersion)) {
			$options['pcre_version'] = $this->pcreVersion->getVersionString();
		}

		return $options;
	}

	/**
	 * A body position (root, group body, quantified atom): a sequence of one
	 * unit collapses to that unit, an empty body disappears, several units or
	 * literal characters are wrapped in a concatenation, as the grammar does.
	 */
	private static function convertBody(NodeInterface $node): ?RegexTreeNode
	{
		if ($node instanceof SequenceNode) {
			return self::fold($node->children);
		}

		if ($node instanceof LiteralNode && $node->value === '') {
			return null;
		}

		$tokens = self::convert($node);

		return self::wrap($tokens);
	}

	/**
	 * @param list<NodeInterface> $nodes
	 */
	private static function fold(array $nodes): ?RegexTreeNode
	{
		$tokens = [];
		foreach ($nodes as $child) {
			if ($child instanceof LiteralNode && $child->value === '') {
				continue;
			}

			$tokens = array_merge($tokens, self::convert($child));
		}

		return self::wrap($tokens);
	}

	/**
	 * @param list<RegexTreeNode> $tokens
	 */
	private static function wrap(array $tokens): ?RegexTreeNode
	{
		if ($tokens === []) {
			return null;
		}

		if (count($tokens) === 1) {
			return $tokens[0];
		}

		return new RegexTreeNode('#concatenation', null, $tokens);
	}

	/**
	 * @return list<RegexTreeNode>
	 */
	private static function convert(NodeInterface $node): array
	{
		if ($node instanceof LiteralNode) {
			$tokens = [];
			// one token per character, as the grammar emits; a literal "." is
			// always escaped here so it stays a constant instead of any-char
			foreach (mb_str_split($node->value, encoding: 'UTF-8') as $char) {
				$tokens[] = self::token('literal', $char === '.' ? '\\.' : $char);
			}

			return $tokens;
		}

		if ($node instanceof DotNode) {
			return [self::token('literal', '.')];
		}

		if ($node instanceof AnchorNode) {
			return [self::token('anchor', $node->value)];
		}

		if ($node instanceof AssertionNode) {
			return [self::token('anchor', '\\' . $node->value)];
		}

		if ($node instanceof KeepNode) {
			return [self::token('match_point_reset', '\\K')];
		}

		if ($node instanceof CharTypeNode) {
			return [self::token('character_type', '\\' . $node->value)];
		}

		if ($node instanceof UnicodePropNode) {
			return [self::token('character_type', self::unicodeProperty($node))];
		}

		if ($node instanceof CharLiteralNode) {
			return [self::token('dynamic_character', self::character($node->codePoint))];
		}

		if ($node instanceof ControlCharNode) {
			return [self::token('character', self::character($node->codePoint))];
		}

		// the 2017 grammar reads these as literal characters: "1" for \1,
		// "k<n>" for \k<n>, "Q...E" for \Q...\E. An unknown token name instead
		// makes the walkers drop their constant literals and fall back to
		// string types, which is what a backreference actually guarantees.
		if ($node instanceof BackrefNode) {
			return [self::token('backreference', $node->ref)];
		}

		if ($node instanceof SubroutineNode) {
			return [self::token('subroutine', $node->reference)];
		}

		if ($node instanceof CalloutNode) {
			return [self::token('callout', '')];
		}

		if ($node instanceof PcreVerbNode) {
			if (str_starts_with($node->verb, 'MARK:')) {
				return [new RegexTreeNode('#mark', null, [self::token('name', substr($node->verb, 5))])];
			}

			return [self::token('pcre_verb', $node->verb)];
		}

		if ($node instanceof CommentNode) {
			return [new RegexTreeNode('#comment', null, [self::token('comment', $node->comment)])];
		}

		if ($node instanceof GroupNode) {
			return [self::convertGroup($node)];
		}

		if ($node instanceof QuantifierNode) {
			return [self::convertQuantifier($node)];
		}

		if ($node instanceof CharClassNode) {
			return [self::convertClass($node)];
		}

		if ($node instanceof ConditionalNode) {
			return [self::convertConditional($node)];
		}

		if ($node instanceof DefineNode) {
			return self::convertDefinition($node);
		}

		if ($node instanceof AlternationNode) {
			return [self::convertAlternation($node)];
		}

		if ($node instanceof SequenceNode) {
			return array_merge(...array_map(self::convert(...), $node->children));
		}

		return [self::token('phpregex_node', $node::class)];
	}

	private static function convertAlternation(AlternationNode $node): RegexTreeNode
	{
		$children = [];
		foreach ($node->alternatives as $i => $alternative) {
			if ($i > 0) {
				$children[] = self::token('alternation', '|');
			}

			$body = self::convertBody($alternative);
			if ($body !== null) {
				$children[] = $body;
			}
		}

		return new RegexTreeNode('#alternation', null, $children);
	}

	private static function convertGroup(GroupNode $node): RegexTreeNode
	{
		$body = self::convertBody($node->child);

		if ($node->type === GroupType::Named()) {
			$children = [self::token('capturing_name', (string) $node->name)];
			if ($body !== null) {
				$children[] = $body;
			}

			return new RegexTreeNode('#namedcapturing', null, $children);
		}

		$id = match ($node->type) {
			GroupType::Capturing() => '#capturing',
			GroupType::NonCapturing() => '#noncapturing',
			GroupType::Atomic() => '#atomicgroup',
			GroupType::BranchReset() => '#noncapturingreset',
			GroupType::LookaheadPositive() => '#lookahead',
			GroupType::LookaheadNegative() => '#negativelookahead',
			GroupType::LookbehindPositive() => '#lookbehind',
			GroupType::LookbehindNegative() => '#negativelookbehind',
			GroupType::InlineFlags(), GroupType::ScanSubstring() => '#noncapturing',
		};

		if ($node->type === GroupType::InlineFlags() && $body === null) {
			// an option-setting group like (?i) stands on its own
			return new RegexTreeNode('#internal_options', null, [
				self::token('internal_option', '(?' . $node->flags . ')'),
			]);
		}

		$children = [];
		if ($node->type === GroupType::InlineFlags()) {
			$children[] = self::token('non_capturing_internal_option', '(?' . $node->flags . ':');
		}

		if ($body !== null) {
			$children[] = $body;
		}

		return new RegexTreeNode($id, null, $children);
	}

	private static function convertQuantifier(QuantifierNode $node): RegexTreeNode
	{
		$bounds = QuantifierBounds::parse($node->quantifier);

		$suffix = match ($node->type) {
			QuantifierType::Lazy() => '_lazy',
			QuantifierType::Possessive() => '_possessive',
			QuantifierType::Greedy() => '',
		};

		if ($bounds === null) {
			return new RegexTreeNode('#quantification', null, array_merge(self::convert($node->node), [
				self::token('quantifier' . $suffix, $node->quantifier),
			]));
		}

		$min = $bounds->min;
		$max = $bounds->max;

		if ($max === null) {
			if ($min === 0) {
				[$name, $value] = ['zero_or_more', '*'];
			} elseif ($min === 1) {
				[$name, $value] = ['one_or_more', '+'];
			} else {
				[$name, $value] = ['n_or_more', '{' . $min . ',}'];
			}
		} elseif ($min === 0 && $max === 1) {
			[$name, $value] = ['zero_or_one', '?'];
		} elseif ($min === $max) {
			[$name, $value] = ['exactly_n', '{' . $min . '}'];
		} else {
			// also normalizes "{,5}" the walkers could not sscanf
			[$name, $value] = ['n_to_m', '{' . $min . ',' . $max . '}'];
		}

		return new RegexTreeNode('#quantification', null, array_merge(self::convert($node->node), [
			self::token($name . $suffix, $value),
		]));
	}

	private static function convertClass(CharClassNode $node): RegexTreeNode
	{
		$expression = $node->expression;
		$members = $expression instanceof AlternationNode ? $expression->alternatives : [$expression];

		$children = [];
		foreach ($members as $member) {
			$children = array_merge($children, self::convertClassMember($member));
		}

		return new RegexTreeNode($node->isNegated ? '#negativeclass' : '#class', null, $children);
	}

	/**
	 * @return list<RegexTreeNode>
	 */
	private static function convertClassMember(NodeInterface $node): array
	{
		if ($node instanceof RangeNode) {
			return [new RegexTreeNode('#range', null, array_merge(self::convert($node->start), self::convert($node->end)))];
		}

		if ($node instanceof PosixClassNode) {
			return [self::token('posix_class', '[:' . $node->class . ':]')];
		}

		if ($node instanceof CharTypeNode) {
			return [self::token('character_type', '\\' . $node->value)];
		}

		if ($node instanceof UnicodePropNode) {
			return [self::token('character_type', self::unicodeProperty($node))];
		}

		if ($node instanceof CharLiteralNode) {
			return [self::token('dynamic_character', self::character($node->codePoint))];
		}

		if ($node instanceof ControlCharNode) {
			return [self::token('character', self::character($node->codePoint))];
		}

		if ($node instanceof LiteralNode) {
			// inside a class every member is a plain literal, "." included
			$tokens = [];
			foreach (mb_str_split($node->value, encoding: 'UTF-8') as $char) {
				$tokens[] = self::token('literal', $char);
			}

			return $tokens;
		}

		return [self::token('class_member', $node::class)];
	}

	private static function convertConditional(ConditionalNode $node): RegexTreeNode
	{
		$condition = $node->condition;

		if ($condition instanceof BackrefNode) {
			$ref = $condition->ref;
			if (ctype_digit($ref)) {
				$id = '#absolutecondition';
			} elseif (str_starts_with($ref, '-') || str_starts_with($ref, '+')) {
				$id = '#relativecondition';
			} else {
				$id = '#namedcondition';
			}

			$children = [$id === '#namedcondition'
				? self::token('capturing_name', $ref)
				: self::token('index', $ref)];
		} elseif ($condition instanceof VersionConditionNode) {
			$children = [self::token('version_condition', $condition->operator . $condition->version)];
			$id = '#assertioncondition';
		} elseif ($condition instanceof SubroutineNode) {
			$children = [self::token('recursion_condition', $condition->reference)];
			$id = '#assertioncondition';
		} else {
			$children = array_merge(self::convert($condition));
			$id = '#assertioncondition';
		}

		$body = self::convertConditionBody($node->yes, $node->no);
		if ($body !== null) {
			$children[] = $body;
		}

		return new RegexTreeNode($id, null, $children);
	}

	private static function convertConditionBody(NodeInterface $yes, NodeInterface $no): ?RegexTreeNode
	{
		$yesNode = self::convertBody($yes);
		$noNode = self::convertBody($no);

		if ($yesNode === null && $noNode === null) {
			return null;
		}

		if ($noNode === null) {
			return $yesNode;
		}

		if ($yesNode === null) {
			return $noNode;
		}

		return new RegexTreeNode('#alternation', null, [$yesNode, self::token('alternation', '|'), $noNode]);
	}

	/**
	 * @return list<RegexTreeNode>
	 */
	private static function convertDefinition(DefineNode $node): array
	{
		$children = [self::token('define', 'DEFINE')];

		$body = self::convertBody($node->content);
		if ($body !== null) {
			$children[] = $body;
		}

		return [new RegexTreeNode('#assertioncondition', null, $children)];
	}

	private static function unicodeProperty(UnicodePropNode $node): string
	{
		// a caret inside the property already carries the negation
		$letter = str_starts_with($node->prop, '^') || str_starts_with($node->prop, '{^')
			? 'p'
			: ($node->negatedSyntax ? 'P' : 'p');

		return '\\' . $letter . $node->prop;
	}

	private static function character(int $codePoint): string
	{
		return mb_chr($codePoint, 'UTF-8') ?? "\x00";
	}

	private static function token(string $name, string $value): RegexTreeNode
	{
		return new RegexTreeNode('token', ['token' => $name, 'value' => $value, 'namespace' => 'default']);
	}

}
