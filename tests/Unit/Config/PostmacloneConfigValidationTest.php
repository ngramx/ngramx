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

    public function test_requires_tables_or_prebuilt(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('prebuilt');
        $this->validator->validate($this->base([
            'postmaclone' => [
                'engine' => 'postgres',
            ],
        ]));
    }

    public function test_accepts_prebuilt_without_tables(): void
    {
        $this->validator->validate($this->base([
            'postmaclone' => [
                'engine' => 'postgres',
                'prebuilt' => [
                    'path' => 'spaces://anon-bucket/project/',
                    'file' => 'anon.sql.gz',
                    'region' => 'lon1',
                    'endpoint' => 'https://lon1.digitaloceanspaces.com',
                    'credentials' => [
                        'key' => 'op://Vault/anon-read/username',
                        'secret' => 'op://Vault/anon-read/credential',
                    ],
                ],
            ],
        ]));
        $this->assertTrue(true);
    }

    public function test_factory_config_loads(): void
    {
        $path = dirname(__DIR__, 2) . '/fixtures/postmaclone/factory-postmaclone.yml';
        $loader = new ConfigLoader($this->validator);
        $factory = $loader->loadFactory($path);
        $this->assertArrayHasKey('demo', $factory->datasets);
        $this->assertSame(['users'], $factory->datasets['demo']->includeTables);
        $this->assertSame(['audit_log'], $factory->datasets['demo']->excludeTables);
        $this->assertSame('spaces://anon-bucket/demo/', $factory->datasets['demo']->publish->path);
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

    public function test_accepts_connection_backup_source(): void
    {
        $this->validator->validate($this->base([
            'postmaclone' => [
                'engine' => 'mysql',
                'tables' => ['users' => ['email' => 'safeEmail']],
                'backup' => [
                    'source' => 'connection',
                ],
            ],
        ]));
        $this->assertTrue(true);
    }

    public function test_rejects_unknown_backup_source(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('postmaclone.backup.source');
        $this->validator->validate($this->base([
            'postmaclone' => [
                'engine' => 'mysql',
                'tables' => ['users' => ['email' => 'safeEmail']],
                'backup' => [
                    'source' => 'ftp',
                ],
            ],
        ]));
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
            'compose_file: "' . str_replace('\\', '/', dirname(__DIR__, 2) . '/fixtures/postmaclone/compose-postgres.yml') . '"',
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
