<?php declare(strict_types = 1);

namespace PHPStan\Type\Php;

use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\Native\NativeParameterReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\PassedByReference;
use PHPStan\TrinaryLogic;
use PHPStan\Type\ClosureType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\FunctionParameterClosureTypeExtension;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use function count;

/**
 * Types the callbacks of a preg_replace_callback_array() pattern => callback literal,
 * each from the shape of its own pattern.
 */
#[AutowiredService]
final class PregReplaceCallbackArrayClosureTypeExtension implements FunctionParameterClosureTypeExtension
{

	public function __construct(
		private RegexArrayShapeMatcher $regexShapeMatcher,
	)
	{
	}

	public function isFunctionSupported(FunctionReflection $functionReflection, ParameterReflection $parameter): bool
	{
		return $functionReflection->getName() === 'preg_replace_callback_array' && $parameter->getName() === 'pattern';
	}

	public function getTypeFromFunctionCall(FunctionReflection $functionReflection, FuncCall $functionCall, ParameterReflection $parameter, Scope $scope): ?Type
	{
		$args = $functionCall->getArgs();
		$patternArg = $args[0] ?? null;
		$flagsArg = $args[4] ?? null;

		if ($patternArg === null || !$patternArg->value instanceof Array_) {
			return null;
		}

		$flagsType = null;
		if ($flagsArg !== null) {
			$flagsType = $scope->getType($flagsArg->value);
		}

		$builder = ConstantArrayTypeBuilder::createEmpty();
		$builder->disableClosureDegradation();
		foreach ($patternArg->value->items as $item) {
			// a callback whose pattern is not known could get the shape of another one
			if ($item->key === null || $item->unpack) {
				return null;
			}

			$keyType = $scope->getType($item->key);
			if (count($keyType->getConstantStrings()) !== 1) {
				return null;
			}

			$matchesType = $this->regexShapeMatcher->matchExpr($item->key, $flagsType, TrinaryLogic::createYes(), $scope, true);
			if ($matchesType === null) {
				$builder->setOffsetValueType($keyType, $parameter->getType()->getIterableValueType());
				continue;
			}

			$builder->setOffsetValueType($keyType, new ClosureType(
				[
					new NativeParameterReflection('matches', false, $matchesType, PassedByReference::createNo(), false, null),
				],
				new StringType(),
			));
		}

		$arrayType = $builder->getArray();
		if (!$arrayType->isConstantArray()->yes()) {
			return null;
		}

		return $arrayType;
	}

}
