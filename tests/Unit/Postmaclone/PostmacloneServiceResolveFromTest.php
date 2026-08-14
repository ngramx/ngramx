<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Config\Schema\AgentsConfig;
use Ngramx\Config\Schema\DockerConfig;
use Ngramx\Config\Schema\N8nConfig;
use Ngramx\Config\Schema\NgramxConfig;
use Ngramx\Config\Schema\Postmaclone\BackupConfig;
use Ngramx\Config\Schema\Postmaclone\PostmacloneConfig;
use Ngramx\Config\Schema\SetupConfig;
use Ngramx\Postmaclone\Exception\PostmacloneException;
use Ngramx\Postmaclone\PostmacloneService;
use PHPUnit\Framework\TestCase;

class PostmacloneServiceResolveFromTest extends TestCase
{
    private string $dir;

    /** @var list<string> */
    private array $envKeysToClear = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ngramx-resolve-from-' . uniqid('', true);
        mkdir($this->dir, 0700, true);
        $this->clearSourceUrlEnv();
    }

    protected function tearDown(): void
    {
        $this->clearSourceUrlEnv();
        foreach (['.env', '.env.postmaclone'] as $file) {
            $path = $this->dir . '/' . $file;
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function test_cli_from_wins_over_env(): void
    {
        putenv(PostmacloneService::SOURCE_URL_ENV . '=mysql://env:x@127.0.0.1/envdb');
        $this->envKeysToClear[] = PostmacloneService::SOURCE_URL_ENV;

        $from = $this->service()->resolveFrom(
            'mysql://cli:y@127.0.0.1/clidb',
            $this->config(BackupConfig::SOURCE_CONNECTION),
            $this->dir,
        );

        $this->assertNotNull($from);
        $this->assertTrue($from->isConnection());
        $this->assertSame('mysql://cli:y@127.0.0.1/clidb', $from->value);
    }

    public function test_reads_process_env_when_source_is_connection(): void
    {
        putenv(PostmacloneService::SOURCE_URL_ENV . '=mysql://env:secret@127.0.0.1:3306/app');
        $this->envKeysToClear[] = PostmacloneService::SOURCE_URL_ENV;

        $from = $this->service()->resolveFrom(
            null,
            $this->config(BackupConfig::SOURCE_CONNECTION),
            $this->dir,
        );

        $this->assertNotNull($from);
        $this->assertTrue($from->isConnection());
        $this->assertSame('mysql://env:secret@127.0.0.1:3306/app', $from->value);
        $this->assertSame('mysql', $from->engineHint);
    }

    public function test_reads_dotenv_when_process_env_missing(): void
    {
        file_put_contents(
            $this->dir . '/.env',
            PostmacloneService::SOURCE_URL_ENV . "=mysql://dotenv:pw@db.internal/app\n"
        );

        $from = $this->service()->resolveFrom(
            null,
            $this->config(BackupConfig::SOURCE_CONNECTION),
            $this->dir,
        );

        $this->assertNotNull($from);
        $this->assertTrue($from->isConnection());
        $this->assertSame('mysql://dotenv:pw@db.internal/app', $from->value);
    }

    public function test_reads_env_postmaclone_when_dotenv_missing(): void
    {
        file_put_contents(
            $this->dir . '/.env.postmaclone',
            PostmacloneService::SOURCE_URL_ENV . "=mysql://pmc:pw@db.internal/app\n"
        );

        $from = $this->service()->resolveFrom(
            null,
            $this->config(BackupConfig::SOURCE_CONNECTION),
            $this->dir,
        );

        $this->assertNotNull($from);
        $this->assertSame('mysql://pmc:pw@db.internal/app', $from->value);
    }

    public function test_ignores_env_when_source_is_not_connection(): void
    {
        putenv(PostmacloneService::SOURCE_URL_ENV . '=mysql://env:secret@127.0.0.1:3306/app');
        $this->envKeysToClear[] = PostmacloneService::SOURCE_URL_ENV;

        $from = $this->service()->resolveFrom(
            null,
            $this->config(BackupConfig::SOURCE_LOCAL),
            $this->dir,
        );

        $this->assertNull($from);
    }

    public function test_throws_when_connection_source_missing_url(): void
    {
        $this->expectException(PostmacloneException::class);
        $this->expectExceptionMessage(PostmacloneService::SOURCE_URL_ENV);

        $this->service()->resolveFrom(
            null,
            $this->config(BackupConfig::SOURCE_CONNECTION),
            $this->dir,
        );
    }

    private function service(): PostmacloneService
    {
        return new PostmacloneService();
    }

    private function config(string $backupSource): NgramxConfig
    {
        return new NgramxConfig(
            version: '1.0',
            docker: new DockerConfig(
                composeFile: 'docker-compose.yml',
                primaryService: 'app',
                appUrl: 'http://localhost',
            ),
            setup: new SetupConfig(),
            n8n: new N8nConfig(workflowsDir: '/tmp'),
            agents: new AgentsConfig(),
            postmaclone: new PostmacloneConfig(
                engine: 'mysql',
                backup: new BackupConfig(source: $backupSource),
            ),
        );
    }

    private function clearSourceUrlEnv(): void
    {
        putenv(PostmacloneService::SOURCE_URL_ENV);
        foreach ($this->envKeysToClear as $key) {
            putenv($key);
        }
        $this->envKeysToClear = [];
    }
}
