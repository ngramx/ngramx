<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Config;

use Ngramx\Config\ConfigLoader;
use Ngramx\Config\Exception\ConfigException;
use Ngramx\Config\Validator\ConfigValidator;
use PHPUnit\Framework\TestCase;

class PostmacloneConfigValidationTest extends TestCase
{
    private ConfigValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ConfigValidator();
    }

    public function test_valid_postmaclone_section(): void
    {
        $this->validator->validate($this->base([
            'postmaclone' => [
                'engine' => 'postgres',
                'tables' => [
                    'users' => [
                        'email' => 'safeEmail',
                        'first_name' => 'firstName',
                    ],
                ],
            ],
        ]));
        $this->assertTrue(true);
    }

    public function test_requires_tables(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('postmaclone.tables');
        $this->validator->validate($this->base([
            'postmaclone' => [
                'engine' => 'postgres',
            ],
        ]));
    }

    public function test_rejects_unknown_engine(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('postmaclone.engine');
        $this->validator->validate($this->base([
            'postmaclone' => [
                'engine' => 'mongodb',
                'tables' => ['users' => ['email' => 'safeEmail']],
            ],
        ]));
    }

    public function test_rejects_plaintext_backup_credentials(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('op://');
        $this->validator->validate($this->base([
            'postmaclone' => [
                'engine' => 'postgres',
                'tables' => ['users' => ['email' => 'safeEmail']],
                'backup' => [
                    'credentials' => [
                        'key' => 'DO00PLAINTEXT',
                        'secret' => 'also-plaintext',
                    ],
                ],
            ],
        ]));
    }

    public function test_accepts_op_backup_credentials(): void
    {
        $this->validator->validate($this->base([
            'postmaclone' => [
                'engine' => 'postgres',
                'tables' => ['users' => ['email' => 'safeEmail']],
                'backup' => [
                    'credentials' => [
                        'key' => 'op://Tech Team Vault/ngramx-db-backup-read-access/username',
                        'secret' => 'op://Tech Team Vault/ngramx-db-backup-read-access/credential',
                    ],
                ],
            ],
        ]));
        $this->assertTrue(true);
    }

    public function test_loader_builds_opt_in_columns(): void
    {
        $path = dirname(__DIR__, 2) . '/fixtures/ngramx-with-postmaclone.yml';
        $loader = new ConfigLoader(new ConfigValidator());
        // compose_file is relative — load from fixture dir by copying to temp with absolute compose
        $tmp = sys_get_temp_dir() . '/ngramx-pm-' . uniqid('', true);
        mkdir($tmp);
        $yml = file_get_contents($path);
        $this->assertIsString($yml);
        $yml = str_replace(
            'compose_file: "docker-compose.yml"',
            'compose_file: "' . dirname(__DIR__, 2) . '/fixtures/postmaclone/compose-postgres.yml"',
            $yml
        );
        $configPath = $tmp . '/ngramx.yml';
        file_put_contents($configPath, $yml);

        $config = $loader->load($configPath);
        $this->assertNotNull($config->postmaclone);
        $this->assertArrayHasKey('users', $config->postmaclone->tables);
        $this->assertArrayHasKey('email', $config->postmaclone->tables['users']->columns);
        $this->assertArrayNotHasKey('status', $config->postmaclone->tables['users']->columns);

        unlink($configPath);
        rmdir($tmp);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function base(array $extra = []): array
    {
        return array_merge([
            'version' => '1.0',
            'docker' => [
                'compose_file' => 'docker-compose.yml',
                'primary_service' => 'app',
                'app_url' => 'http://localhost:8080',
            ],
        ], $extra);
    }
}
