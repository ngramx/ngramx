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
                    $providerConfig->required,
                    rtrim($configDirectory, '/') . '/.env'
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
     * The shell provider is satisfied by an exported variable *or* by the
     * project's own .env file. Docker Compose loads that file for interpolation
     * and build args, so a value living only there is genuinely available to the
     * stack — and it is what our own failure hint tells people to edit. Checking
     * `getenv()` alone made `up`/`worktree` fail on projects whose credentials
     * were correctly recorded in .env.
     *
     * @param string[] $required
     * @return string[]
     */
    private function validateShellProvider(array $required, string $envFilePath): array
    {
        $dotEnvValues = $this->dotEnvFileReader->read($envFilePath) ?? [];

        $missing = [];
        foreach ($required as $name) {
            if ($this->hasNonEmptyValue($this->getEnvVar($name))) {
                continue;
            }

            if ($this->hasNonEmptyValue($dotEnvValues[$name] ?? false)) {
                continue;
            }

            $missing[] = $name;
        }

        return $missing;
    }

    private function hasNonEmptyValue(string|false $value): bool
    {
        return $value !== false && trim($value) !== '';
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
            SecretsProviderConfig::PROVIDER_SHELL => 'the shell environment or .env file',
            default => "{$provider} provider",
        };
    }

    public static function describeMissingSource(string $provider): string
    {
        return match ($provider) {
            SecretsProviderConfig::PROVIDER_DOTENV => 'the .env file',
            SecretsProviderConfig::PROVIDER_SHELL => 'the shell environment or .env file',
            default => $provider,
        };
    }

    protected function getEnvVar(string $name): string|false
    {
        return getenv($name);
    }
}
