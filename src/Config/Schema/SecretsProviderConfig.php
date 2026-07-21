<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema;

readonly class SecretsProviderConfig
{
    public const PROVIDER_SHELL = 'shell';
    public const PROVIDER_DOTENV = '.env';

    /** @deprecated Use {@see self::PROVIDER_SHELL}. Accepted in config for backwards compatibility only. */
    public const PROVIDER_ENV = 'env';

    /**
     * @param string[] $required
     */
    public function __construct(
        public string $provider = self::PROVIDER_SHELL,
        public array $required = [],
    ) {
    }

    public static function normalizeProvider(string $provider): string
    {
        return $provider === self::PROVIDER_ENV ? self::PROVIDER_SHELL : $provider;
    }

    public static function isShellProvider(string $provider): bool
    {
        return $provider === self::PROVIDER_SHELL || $provider === self::PROVIDER_ENV;
    }
}
