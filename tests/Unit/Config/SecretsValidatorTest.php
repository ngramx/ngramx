<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Config;

use Ngramx\Config\DotEnvFileReader;
use Ngramx\Config\Schema\SecretsConfig;
use Ngramx\Config\Schema\SecretsProviderConfig;
use Ngramx\Config\Validator\SecretsValidator;
use PHPUnit\Framework\TestCase;

class SecretsValidatorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ngramx-secrets-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    public function test_it_returns_empty_array_when_no_secrets_required(): void
    {
        $validator = new SecretsValidator();
        $secrets = new SecretsConfig(providers: []);

        $this->assertSame([], $validator->validate($secrets, $this->tmpDir));
    }

    public function test_it_returns_empty_array_when_all_env_secrets_present(): void
    {
        $validator = $this->createValidatorWithEnv([
            'SECRET_ONE' => 'value1',
            'SECRET_TWO' => 'value2',
        ]);

        $secrets = new SecretsConfig(providers: [
            new SecretsProviderConfig(required: ['SECRET_ONE', 'SECRET_TWO']),
        ]);

        $this->assertSame([], $validator->validate($secrets, $this->tmpDir));
    }

    public function test_it_returns_missing_env_secrets_grouped_by_provider(): void
    {
        $validator = $this->createValidatorWithEnv([
            'SECRET_ONE' => 'value1',
        ]);

        $secrets = new SecretsConfig(providers: [
            new SecretsProviderConfig(required: ['SECRET_ONE', 'SECRET_TWO', 'SECRET_THREE']),
        ]);

        $missing = $validator->validate($secrets, $this->tmpDir);

        $this->assertSame(['shell' => ['SECRET_TWO', 'SECRET_THREE']], $missing);
    }

    public function test_it_validates_dotenv_provider_against_env_file(): void
    {
        file_put_contents($this->tmpDir . '/.env', "APP_KEY=secret\nDB_PASSWORD=pass\n");

        $validator = new SecretsValidator();
        $secrets = new SecretsConfig(providers: [
            new SecretsProviderConfig(
                provider: SecretsProviderConfig::PROVIDER_DOTENV,
                required: ['APP_KEY', 'DB_PASSWORD'],
            ),
        ]);

        $this->assertSame([], $validator->validate($secrets, $this->tmpDir));
    }

    public function test_it_reports_missing_dotenv_secrets(): void
    {
        file_put_contents($this->tmpDir . '/.env', "APP_KEY=secret\n");

        $validator = new SecretsValidator();
        $secrets = new SecretsConfig(providers: [
            new SecretsProviderConfig(
                provider: SecretsProviderConfig::PROVIDER_DOTENV,
                required: ['APP_KEY', 'DB_PASSWORD'],
            ),
        ]);

        $missing = $validator->validate($secrets, $this->tmpDir);

        $this->assertSame(['.env' => ['DB_PASSWORD']], $missing);
    }

    public function test_it_treats_missing_env_file_as_all_dotenv_secrets_missing(): void
    {
        $validator = new SecretsValidator();
        $secrets = new SecretsConfig(providers: [
            new SecretsProviderConfig(
                provider: SecretsProviderConfig::PROVIDER_DOTENV,
                required: ['APP_KEY', 'DB_PASSWORD'],
            ),
        ]);

        $missing = $validator->validate($secrets, $this->tmpDir);

        $this->assertSame(['.env' => ['APP_KEY', 'DB_PASSWORD']], $missing);
    }

    public function test_it_validates_multiple_providers_independently(): void
    {
        file_put_contents($this->tmpDir . '/.env', "APP_KEY=secret\n");

        $validator = $this->createValidatorWithEnv([
            'HOST_SECRET' => 'present',
        ]);

        $secrets = new SecretsConfig(providers: [
            new SecretsProviderConfig(
                provider: SecretsProviderConfig::PROVIDER_DOTENV,
                required: ['APP_KEY', 'DB_PASSWORD'],
            ),
            new SecretsProviderConfig(
                provider: SecretsProviderConfig::PROVIDER_SHELL,
                required: ['HOST_SECRET', 'MISSING_ENV_SECRET'],
            ),
        ]);

        $missing = $validator->validate($secrets, $this->tmpDir);

        $this->assertSame([
            '.env' => ['DB_PASSWORD'],
            'shell' => ['MISSING_ENV_SECRET'],
        ], $missing);
    }

    public function test_it_still_validates_obsolete_env_provider_for_backwards_compatibility(): void
    {
        $validator = $this->createValidatorWithEnv([]);

        $secrets = new SecretsConfig(providers: [
            new SecretsProviderConfig(
                provider: SecretsProviderConfig::PROVIDER_ENV,
                required: ['MISSING_SECRET'],
            ),
        ]);

        $missing = $validator->validate($secrets, $this->tmpDir);

        $this->assertSame(['shell' => ['MISSING_SECRET']], $missing);
    }

    public function test_it_treats_empty_string_env_value_as_present(): void
    {
        $validator = $this->createValidatorWithEnv([
            'EMPTY_SECRET' => '',
        ]);

        $secrets = new SecretsConfig(providers: [
            new SecretsProviderConfig(required: ['EMPTY_SECRET']),
        ]);

        $this->assertSame([], $validator->validate($secrets, $this->tmpDir));
    }

    public function test_it_treats_empty_string_dotenv_value_as_present(): void
    {
        file_put_contents($this->tmpDir . '/.env', "EMPTY_SECRET=\n");

        $validator = new SecretsValidator();
        $secrets = new SecretsConfig(providers: [
            new SecretsProviderConfig(
                provider: SecretsProviderConfig::PROVIDER_DOTENV,
                required: ['EMPTY_SECRET'],
            ),
        ]);

        $this->assertSame([], $validator->validate($secrets, $this->tmpDir));
    }

    /**
     * @param array<string, string> $env
     */
    private function createValidatorWithEnv(array $env): SecretsValidator
    {
        return new class ($env) extends SecretsValidator {
            /** @param array<string, string> $env */
            public function __construct(private readonly array $env)
            {
                parent::__construct();
            }

            protected function getEnvVar(string $name): string|false
            {
                return $this->env[$name] ?? false;
            }
        };
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
