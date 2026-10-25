<?php declare(strict_types = 1);

namespace PHPStan\Php;

use InvalidArgumentException;
use PHPStan\DependencyInjection\AutowiredService;
use function explode;
use function intdiv;
use function preg_match;
use function sprintf;
use const PCRE_VERSION;

/**
 * Represents the PCRE2 release the regex reads are judged for, the
 * engine-side twin of PhpVersion. The version is stored as major * 100 +
 * minor (e.g. 1042 for 10.42); a release candidate reads as its release.
 * The source says how much the version claims: a pin from the
 * `pcreVersion` parameter or `config.platform.lib-pcre` is a statement
 * about the analysed project, the runtime is a witness that speaks for
 * the analysed PHP when the two share a minor. Extension developers can
 * access it by injecting PcreVersion via constructor injection.
 *
 * @api
 */
#[AutowiredService(factory: '@PHPStan\Php\PcreVersionFactory::create')]
final class PcreVersion
{

	public const SOURCE_RUNTIME = 1;
	public const SOURCE_CONFIG = 2;
	public const SOURCE_COMPOSER_PLATFORM_PCRE = 3;
	public const SOURCE_UNKNOWN = 4;

	/**
	 * @api
	 * @param self::SOURCE_* $source
	 */
	public function __construct(private int $versionId, private int $source = self::SOURCE_UNKNOWN)
	{
	}

	/**
	 * @param self::SOURCE_* $source
	 */
	public static function fromString(string $version, int $source = self::SOURCE_UNKNOWN): self
	{
		// two digits on each side, as PCRE2 writes releases: 10.42, 11.00.
		// A release candidate carries its date after a dash - "10.49-RC1
		// 2026-08-14" - and reads as the release it carries the features of
		if (preg_match('/^(\d{2})\.(\d{2})(?:[^\d].*)?$/', $version, $matches) !== 1) {
			throw new InvalidArgumentException(sprintf(
				'"%s" is not a PCRE2 release. Expected a version like "10.42".',
				$version,
			));
		}

		return new self((int) $matches[1] * 100 + (int) $matches[2], $source);
	}

	/**
	 * The engine the running PHP links, the release PCRE_VERSION spells
	 * beside its date: "10.49 2026-09-28".
	 */
	public static function fromRuntime(): self
	{
		return self::fromString(explode(' ', PCRE_VERSION, 2)[0], self::SOURCE_RUNTIME);
	}

	/**
	 * @return self::SOURCE_*
	 */
	public function getSource(): int
	{
		return $this->source;
	}

	public function getSourceLabel(): string
	{
		switch ($this->source) {
			case self::SOURCE_RUNTIME:
				return 'runtime';
			case self::SOURCE_CONFIG:
				return 'config';
			case self::SOURCE_COMPOSER_PLATFORM_PCRE:
				return 'config.platform.lib-pcre in composer.json';
		}

		return 'unknown';
	}

	public function getVersionId(): int
	{
		return $this->versionId;
	}

	public function getVersionString(): string
	{
		return sprintf('%d.%02d', intdiv($this->versionId, 100), $this->versionId % 100);
	}

	public function atLeast(string $release): bool
	{
		return $this->versionId >= self::fromString($release)->getVersionId();
	}

}
