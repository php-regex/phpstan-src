<?php declare(strict_types = 1);

namespace PHPStan\Php;

use PHPRegex\Parser\PcreTarget;
use PHPStan\DependencyInjection\AutowiredService;
use const PHP_VERSION_ID;

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
			return PcreVersion::fromString($this->version, PcreVersion::SOURCE_CONFIG);
		}

		if ($this->composerPcreVersion !== null) {
			return PcreVersion::fromString($this->composerPcreVersion, PcreVersion::SOURCE_COMPOSER_PLATFORM_PCRE);
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
