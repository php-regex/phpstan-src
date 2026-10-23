<?php declare(strict_types = 1);

namespace PHPStan\Php;

use PHPRegex\Parser\PcreTarget;
use PHPStan\DependencyInjection\AutowiredService;
use const PHP_VERSION_ID;
use function sprintf;

#[AutowiredService(factory: '@PHPStan\Php\PcreVersionFactoryFactory::create')]
final class PcreVersionFactory
{

	public function __construct(
		private ?string $version,
		private ?string $composerPcreVersion,
	)
	{
	}

	public function create(): PcreVersion
	{
		if ($this->version !== null) {
			try {
				return PcreVersion::fromString($this->version, PcreVersion::SOURCE_CONFIG);
			} catch (\InvalidArgumentException $exception) {
				// the message names the knob to fix, not only the value
				throw new \InvalidArgumentException(sprintf(
					'Invalid pcreVersion configuration: %s Fix the "pcreVersion" parameter, or remove it to read the running engine.',
					$exception->getMessage(),
				), previous: $exception);
			}
		}

		if ($this->composerPcreVersion !== null) {
			try {
				return PcreVersion::fromString($this->composerPcreVersion, PcreVersion::SOURCE_COMPOSER_PLATFORM_PCRE);
			} catch (\InvalidArgumentException $exception) {
				throw new \InvalidArgumentException(sprintf(
					'Invalid composer platform package: %s Fix "config.platform.lib-pcre" in composer.json, or remove it to read the running engine.',
					$exception->getMessage(),
				), previous: $exception);
			}
		}

		try {
			return PcreVersion::fromRuntime();
		} catch (\InvalidArgumentException) {
			// a PCRE_VERSION spelling nobody has seen yet must not take the
			// boot down: fall back to the engine the running PHP bundles
			return PcreVersion::fromString(
				PcreTarget::bundledWith(\PHP_VERSION_ID)->pcreVersion,
				PcreVersion::SOURCE_RUNTIME,
			);
		}
	}

}
