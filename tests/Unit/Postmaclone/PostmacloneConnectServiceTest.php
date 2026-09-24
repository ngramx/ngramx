<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Config\ConfigLoader;
use Ngramx\Config\Schema\NgramxConfig;
use Ngramx\Config\Validator\ConfigValidator;
use Ngramx\Postmaclone\Connect\PostmacloneConnectLock;
use Ngramx\Postmaclone\Connect\PostmacloneConnectService;
use Symfony\Component\Yaml\Yaml;
use Ngramx\Postmaclone\Connect\TrustedEgressDetector;
use Ngramx\Postmaclone\PostmacloneLock;
use Ngramx\Postmaclone\PostmacloneLockData;
use PHPUnit\Framework\TestCase;

class PostmacloneConnectServiceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pm-connect-svc-' . uniqid('', true);
        mkdir($this->dir . '/.ngramx', 0700, true);
        file_put_contents($this->dir . '/.env', "DB_HOST=db\nDB_PORT=5432\nDB_DATABASE=app\nDB_USERNAME=app\nDB_PASSWORD=local\n");
        $fixture = str_replace('\\', '/', dirname(__DIR__, 2) . '/fixtures/postmaclone/compose-postgres.yml');
        $compose = $this->dir . '/docker-compose.yml';
        copy($fixture, $compose);
        file_put_contents($this->dir . '/ngramx.yml', <<<YAML
version: "1.0"
docker:
  compose_file: "{$compose}"
  primary_service: "app"
  app_url: "http://localhost"
postmaclone:
  engine: postgres
  shared:
    url: "postgresql://anon_user:anon_pass@private-db.example.com:25060/demo_anon?sslmode=require"
YAML);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/.ngramx/postmaclone-connect.lock');
        @unlink($this->dir . '/.ngramx/postmaclone.lock');
        @unlink($this->dir . '/.ngramx/postmaclone.env.bak');
        @unlink($this->dir . '/.env');
        @unlink($this->dir . '/docker-compose.override.yml');
        @unlink($this->dir . '/docker-compose.yml');
        @unlink($this->dir . '/ngramx.yml');
        @rmdir($this->dir . '/.ngramx');
        @rmdir($this->dir);
    }

    public function test_should_auto_connect_on_codabyte_or_with_anon_flag(): void
    {
        $config = $this->loadConfig();
        $service = new PostmacloneConnectService();

        $this->assertFalse($service->shouldAutoConnectOnUp($config, false));

        putenv(TrustedEgressDetector::ENV_TRUSTED . '=1');
        try {
            $this->assertTrue($service->shouldAutoConnectOnUp($config, false));
            $this->assertTrue($service->shouldAutoConnectOnUp($config, true));
        } finally {
            putenv(TrustedEgressDetector::ENV_TRUSTED);
        }

        $this->assertTrue($service->shouldAutoConnectOnUp($config, true));
    }

    public function test_direct_connect_patches_env_and_writes_lock(): void
    {
        putenv(TrustedEgressDetector::ENV_TRUSTED . '=1');
        try {
            $config = $this->loadConfig();
            $service = new PostmacloneConnectService();
            $result = $service->connect(
                config: $config,
                projectRoot: $this->dir,
                bindEnv: true,
                probe: false,
            );

            $this->assertSame('direct', $result['lock']->mode);
            $this->assertSame('private-db.example.com', $result['lock']->host);
            $this->assertSame('private-db.example.com', $result['lock']->ideHost);
            $this->assertSame(25060, $result['lock']->idePort);
            $env = file_get_contents($this->dir . '/.env');
            $this->assertIsString($env);
            $this->assertStringContainsString('DB_HOST=private-db.example.com', $env);
            $this->assertTrue((new PostmacloneConnectLock($this->dir))->exists());

            $overridePath = $this->dir . '/docker-compose.override.yml';
            $this->assertFileExists($overridePath);
            $override = Yaml::parseFile($overridePath);
            $this->assertIsArray($override);
            $this->assertSame('private-db.example.com', $override['services']['app']['environment']['DB_HOST']);

            $service->disconnect($this->dir);
            $this->assertFileDoesNotExist($overridePath);
            $restored = file_get_contents($this->dir . '/.env');
            $this->assertIsString($restored);
            $this->assertStringContainsString('DB_HOST=db', $restored);
        } finally {
            putenv(TrustedEgressDetector::ENV_TRUSTED);
        }
    }

    public function test_refuses_connect_when_ephemeral_clone_lock_exists(): void
    {
        putenv(TrustedEgressDetector::ENV_TRUSTED . '=1');
        try {
            $config = $this->loadConfig();
            (new PostmacloneLock($this->dir))->write(new PostmacloneLockData(
                provider: 'docker',
                engine: 'postgres',
                createdAt: date('c'),
                expiresAt: date('c'),
                host: 'db',
                port: 5432,
                database: 'clone',
                username: 'clone',
                password: 'clone',
                databaseUrl: 'postgresql://clone:clone@db:5432/clone',
            ));

            $this->expectException(\Ngramx\Postmaclone\Exception\PostmacloneException::class);
            (new PostmacloneConnectService())->connect($config, $this->dir, probe: false);
        } finally {
            putenv(TrustedEgressDetector::ENV_TRUSTED);
        }
    }

    private function loadConfig(): NgramxConfig
    {
        $loader = new ConfigLoader(new ConfigValidator());
        $cwd = getcwd();
        chdir($this->dir);
        try {
            return $loader->load($this->dir . '/ngramx.yml');
        } finally {
            if (is_string($cwd)) {
                chdir($cwd);
            }
        }
    }
}
