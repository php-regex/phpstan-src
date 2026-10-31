<?php declare(strict_types = 1);

namespace PHPStan\Php;

use PHPStan\DependencyInjection\AutowiredParameter;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Internal\ComposerHelper;
use function count;
use function end;
use function is_string;

#[AutowiredService]
final class PcreVersionFactoryFactory
{

	/**
	 * @param string[] $composerAutoloaderProjectPaths
	 */
	public function __construct(
		#[AutowiredParameter]
		private ?string $pcreVersion,
		#[AutowiredParameter]
		private array $composerAutoloaderProjectPaths,
	)
	{
	}

	public function create(): PcreVersionFactory
	{
		$composerPcreVersion = null;
		if (count($this->composerAutoloaderProjectPaths) > 0) {
			$composerJsonPath = end($this->composerAutoloaderProjectPaths);
			$composer = ComposerHelper::getComposerConfig($composerJsonPath);
			if ($composer !== null) {
				$platformVersion = $composer['config']['platform']['lib-pcre'] ?? null;
				if (is_string($platformVersion)) {
					$composerPcreVersion = $platformVersion;
				}
			}
		}

		$version = null;

		if (is_string($this->pcreVersion)) {
			$version = $this->pcreVersion;
		}

		return new PcreVersionFactory($version, $composerPcreVersion);
	}

}
