<?php declare(strict_types = 1);

/**
 * Replays the witnesses of poc/witnesses/*.json on a PHPStan checkout.
 *
 * Usage: php poc/bin/verify.php <phpstan-checkout>
 *
 * For each witness, the engine runs the call again and must write what the
 * witness recorded; the type the checkout infers for the call then accepts
 * the result or refuses it. On an upstream 2.3.x checkout every witness is
 * refused; on this branch every witness is accepted.
 */

[$self, $root] = $argv + [null, null];
if ($root === null) {
	fwrite(STDERR, "usage: php poc/bin/verify.php <phpstan-checkout>\n");
	exit(2);
}

require $root . '/vendor/autoload.php';

use PHPStan\Testing\PHPStanTestCase;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ConstantTypeHelper;
use PHPStan\Type\Php\RegexArrayShapeMatcher;
use PHPStan\Type\Regex\RegexGroupParser;

final class PocContainer extends PHPStanTestCase
{

	public static function get(string $class): object
	{
		return self::getContainer()->getByType($class);
	}

}

ini_set('pcre.jit', '0');

$side = class_exists('PHPStan\Type\Regex\PHPRegexReader') ? 'PHPRegex' : 'phplrt';
/** @var RegexArrayShapeMatcher $matcher */
$matcher = PocContainer::get(RegexArrayShapeMatcher::class);
/** @var RegexGroupParser $groupParser */
$groupParser = PocContainer::get(RegexGroupParser::class);
$matchPatternType = new ReflectionMethod($matcher, 'matchPatternType');

$engineDiffers = 0;
foreach (glob(__DIR__ . '/../witnesses/*.json') ?: [] as $file) {
	$counts = ['accepted' => 0, 'refused' => 0, 'maybe' => 0, 'no shape' => 0];
	foreach (json_decode((string) file_get_contents($file), true) as $w) {
		$pattern = $w['pattern'];
		$subject = base64_decode($w['subject']);
		if ($w['call'] === 'subject') {
			$written = $subject;
			$result = @preg_match($pattern, $subject);
			$walk = $groupParser->parseGroups($pattern);
			$type = $walk === null ? null : ($side === 'PHPRegex' ? $walk->getSubjectNarrowType() : $walk->getSubjectBaseType());
			$value = new ConstantStringType($subject);
		} else {
			[$call, $flags] = explode(':', $w['call']);
			$flags = $flags === 'null' ? null : (int) $flags;
			$written = [];
			$result = $call === 'match'
				? @preg_match($pattern, $subject, $written, $flags ?? 0)
				: @preg_match_all($pattern, $subject, $written, $flags ?? PREG_PATTERN_ORDER);
			$type = $matchPatternType->invoke($matcher, new ConstantStringType($pattern), $flags === null ? null : new ConstantIntegerType($flags), $call === 'allmaybe' ? TrinaryLogic::createMaybe() : TrinaryLogic::createYes(), $call !== 'match');
			$value = ConstantTypeHelper::getTypeFromValue($written);
		}

		if ($result === false || serialize($written) !== base64_decode($w['engine'])) {
			$engineDiffers++;
			fwrite(STDERR, sprintf("the engine of this PHP (PCRE2 %s) writes something else for %s on %s\n", PCRE_VERSION, $pattern, var_export($subject, true)));
			continue;
		}

		if ($type === null) {
			$counts['no shape']++;
			continue;
		}
		$verdict = $type->isSuperTypeOf($value);
		$counts[$verdict->yes() ? 'accepted' : ($verdict->no() ? 'refused' : 'maybe')]++;
	}
	printf("%-12s %s: %s\n", basename($file, '.json'), $side, json_encode($counts));
}

if ($engineDiffers > 0) {
	fwrite(STDERR, "$engineDiffers witnesses were recorded with another PCRE2 release and were skipped\n");
}
