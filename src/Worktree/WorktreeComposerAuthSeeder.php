<?php

declare(strict_types=1);

namespace Ngramx\Worktree;

use Ngramx\Config\DotEnvFileReader;
use Ngramx\Config\Schema\SecretsConfig;
use Ngramx\Config\Schema\SecretsProviderConfig;
use Ngramx\Output\OutputFormatter;

/**
 * Writes a project-local auth.json for Flux Pro when the configured secrets
 * require FLUX credentials in the worktree's .env file.
 *
 * Container entrypoints often run `composer install` before Laravel boots, and
 * worktree image reuse can leave an older image without entrypoint-side auth
 * setup. Seeding auth.json on the host (bind-mounted into the container) lets
 * Composer authenticate without rebuilding images or relying on shell env passthrough.
 */
class WorktreeComposerAuthSeeder
{
    private const FLUX_HOST = 'composer.fluxui.dev';
    private const USERNAME_KEY = 'FLUX_USERNAME';
    private const LICENSE_KEY = 'FLUX_LICENSE_KEY';

    public function __construct(
        private readonly DotEnvFileReader $dotEnvFileReader = new DotEnvFileReader(),
    ) {
    }

    public function seed(string $worktreePath, SecretsConfig $secrets, OutputFormatter $formatter): void
    {
        if (!$this->requiresFluxCredentials($secrets)) {
            return;
        }

        $envFile = rtrim($worktreePath, '/') . '/.env';
        $values = $this->dotEnvFileReader->read($envFile);
        if ($values === null) {
            return;
        }

        $username = trim($values[self::USERNAME_KEY] ?? '');
        $licenseKey = trim($values[self::LICENSE_KEY] ?? '');
        if ($username === '' || $licenseKey === '') {
            return;
        }

        $authPath = rtrim($worktreePath, '/') . '/auth.json';
        $payload = [
            'http-basic' => [
                self::FLUX_HOST => [
                    'username' => $username,
                    'password' => $licenseKey,
                ],
            ],
        ];

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            $formatter->warning('Could not encode Flux Composer credentials for auth.json.');

            return;
        }

        if (@file_put_contents($authPath, $encoded . "\n") === false) {
            $formatter->warning('Could not write auth.json for Flux Composer authentication.');

            return;
        }

        $formatter->info('Configured Flux Pro Composer authentication (auth.json)');
    }

    private function requiresFluxCredentials(SecretsConfig $secrets): bool
    {
        foreach ($secrets->providers as $provider) {
            if ($provider->provider !== SecretsProviderConfig::PROVIDER_DOTENV) {
                continue;
            }

            $required = $provider->required;
            if (in_array(self::USERNAME_KEY, $required, true)
                && in_array(self::LICENSE_KEY, $required, true)) {
                return true;
            }
        }

        return false;
    }
}
