<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema;

readonly class SecretsProviderConfig
{
    public const PROVIDER_ENV = 'env';
    public const PROVIDER_DOTENV = '.env';

    /**
     * @param string[] $required
     */
    public function __construct(
        public string $provider = self::PROVIDER_ENV,
        public array $required = [],
    ) {
    }
}
