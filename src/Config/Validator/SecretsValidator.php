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

            $missing = match (true) {
                SecretsProviderConfig::isShellProvider($providerConfig->provider) => $this->validateShellProvider(
                    $providerConfig->required
                ),
                $providerConfig->provider === SecretsProviderConfig::PROVIDER_DOTENV => $this->validateDotEnvProvider(
                    $providerConfig->required,
                    rtrim($configDirectory, '/') . '/.env'
                ),
                default => $providerConfig->required,
            };

            if ($missing !== []) {
                $providerKey = SecretsProviderConfig::normalizeProvider($providerConfig->provider);
                $missingByProvider[$providerKey] = $missing;
            }
        }

        return $missingByProvider;
    }

    /**
     * @param string[] $required
     * @return string[]
     */
    private function validateShellProvider(array $required): array
    {
        $missing = [];
        foreach ($required as $name) {
            $value = $this->getEnvVar($name);
            if ($value === false || trim((string) $value) === '') {
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
            if (!array_key_exists($name, $values) || trim($values[$name]) === '') {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    /**
     * @param array<string, string[]> $missingByProvider
     */
    public static function buildFailureMessage(array $missingByProvider): string
    {
        $details = [];
        foreach ($missingByProvider as $provider => $missing) {
            $details[] = self::describeMissingSource($provider) . ' (' . implode(', ', $missing) . ')';
        }

        return 'Missing required secrets: ' . implode('; ', $details) . '.';
    }

    public static function describeProviderLabel(string $provider): string
    {
        return match ($provider) {
            SecretsProviderConfig::PROVIDER_DOTENV => '.env file',
            SecretsProviderConfig::PROVIDER_SHELL => 'shell environment',
            default => "{$provider} provider",
        };
    }

    public static function describeMissingSource(string $provider): string
    {
        return match ($provider) {
            SecretsProviderConfig::PROVIDER_DOTENV => 'the .env file',
            SecretsProviderConfig::PROVIDER_SHELL => 'shell environment variables',
            default => $provider,
        };
    }

    protected function getEnvVar(string $name): string|false
    {
        return getenv($name);
    }
}
