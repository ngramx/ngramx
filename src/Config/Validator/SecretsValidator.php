<?php

declare(strict_types=1);

namespace Ngramx\Config\Validator;

use Ngramx\Config\DotEnvFileReader;
use Ngramx\Config\Schema\SecretsConfig;
use Ngramx\Config\Schema\SecretsProviderConfig;

class SecretsValidator
{
    public function __construct(
        private readonly DotEnvFileReader $dotEnvFileReader = new DotEnvFileReader(),
    ) {
    }

    /**
     * Validate that all required secrets are available for each configured provider.
     *
     * @return array<string, string[]> Missing secret names grouped by provider label
     */
    public function validate(SecretsConfig $secrets, string $configDirectory): array
    {
        if ($secrets->isEmpty()) {
            return [];
        }

        $missingByProvider = [];

        foreach ($secrets->providers as $providerConfig) {
            if ($providerConfig->required === []) {
                continue;
            }

            $missing = match ($providerConfig->provider) {
                SecretsProviderConfig::PROVIDER_ENV => $this->validateEnvProvider($providerConfig->required),
                SecretsProviderConfig::PROVIDER_DOTENV => $this->validateDotEnvProvider(
                    $providerConfig->required,
                    rtrim($configDirectory, '/') . '/.env'
                ),
                default => $providerConfig->required,
            };

            if ($missing !== []) {
                $missingByProvider[$providerConfig->provider] = $missing;
            }
        }

        return $missingByProvider;
    }

    /**
     * @param string[] $required
     * @return string[]
     */
    private function validateEnvProvider(array $required): array
    {
        $missing = [];
        foreach ($required as $name) {
            if ($this->getEnvVar($name) === false) {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    /**
     * @param string[] $required
     * @return string[]
     */
    private function validateDotEnvProvider(array $required, string $envFilePath): array
    {
        $values = $this->dotEnvFileReader->read($envFilePath);
        if ($values === null) {
            return $required;
        }

        $missing = [];
        foreach ($required as $name) {
            if (!array_key_exists($name, $values)) {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    protected function getEnvVar(string $name): string|false
    {
        return getenv($name);
    }
}
