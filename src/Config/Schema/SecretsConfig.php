<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema;

readonly class SecretsConfig
{
    /**
     * @param SecretsProviderConfig[] $providers
     */
    public function __construct(
        public array $providers = [],
    ) {
    }

    public function isEmpty(): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->required !== []) {
                return false;
            }
        }

        return true;
    }

    public function totalRequiredCount(): int
    {
        $count = 0;
        foreach ($this->providers as $provider) {
            $count += count($provider->required);
        }

        return $count;
    }
}
