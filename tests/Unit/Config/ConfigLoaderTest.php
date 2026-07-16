<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Config;

use Ngramx\Config\ConfigLoader;
use Ngramx\Config\Exception\ConfigException;
use Ngramx\Config\Validator\ConfigValidator;
use PHPUnit\Framework\TestCase;

class ConfigLoaderTest extends TestCase
{
    private ConfigLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new ConfigLoader(new ConfigValidator());
    }

    public function test_it_loads_valid_config(): void
    {
        $config = $this->loader->load(__DIR__ . '/../../fixtures/ngramx.yml');

        $this->assertEquals('1.0', $config->version);
        $this->assertEquals('app', $config->docker->primaryService);
        $this->assertCount(1, $config->docker->waitFor);
        $this->assertCount(1, $config->setup->preStart);
        $this->assertCount(1, $config->setup->initialize);
        $this->assertCount(1, $config->commands);
    }

    public function test_it_defaults_readiness_probe_fields_when_omitted(): void
    {
        $config = $this->loader->load(__DIR__ . '/../../fixtures/ngramx.yml');

        $wait = $config->docker->waitFor[0];
        $this->assertSame('app', $wait->service);
        $this->assertSame(30, $wait->timeout);
        $this->assertFalse($wait->healthcheck);
        $this->assertNull($wait->readyCommand);
        $this->assertNull($wait->readyLog);
        $this->assertFalse($wait->hasExplicitProbe());
    }

    public function test_it_parses_readiness_probe_fields(): void
    {
        $config = $this->loader->load(__DIR__ . '/../../fixtures/ngramx-wait-probes.yml');

        $this->assertCount(2, $config->docker->waitFor);

        $app = $config->docker->waitFor[0];
        $this->assertSame('app', $app->service);
        $this->assertSame(300, $app->timeout);
        $this->assertTrue($app->healthcheck);
        $this->assertSame('php artisan --version', $app->readyCommand);
        $this->assertSame('is ready!', $app->readyLog);
        $this->assertTrue($app->hasExplicitProbe());

        $db = $config->docker->waitFor[1];
        $this->assertSame('db', $db->service);
        $this->assertFalse($db->hasExplicitProbe());
    }

    public function test_it_throws_exception_for_missing_file(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Configuration file not found');

        $this->loader->load('/nonexistent/ngramx.yml');
    }

    public function test_it_resolves_relative_compose_file_path(): void
    {
        $config = $this->loader->load(__DIR__ . '/../../fixtures/ngramx.yml');

        // The compose file path should be absolute after loading
        $this->assertStringContainsString('docker-compose.test.yml', $config->docker->composeFile);
        $this->assertTrue(str_starts_with($config->docker->composeFile, '/'));
    }

    public function test_it_loads_app_url(): void
    {
        $config = $this->loader->load(__DIR__ . '/../../fixtures/ngramx.yml');

        $this->assertEquals('http://localhost:8080', $config->docker->appUrl);
    }

    public function test_it_defaults_verify_timeout_to_null_when_omitted(): void
    {
        $config = $this->loader->load(__DIR__ . '/../../fixtures/ngramx.yml');

        $this->assertNull($config->docker->verifyTimeout);
    }

    public function test_it_parses_verify_timeout(): void
    {
        $root = $this->makeTempDir();
        file_put_contents($root . '/ngramx.yml', <<<YAML
            version: "1.0"
            docker:
              compose_file: "docker-compose.yml"
              primary_service: "app"
              app_url: "http://localhost:8080"
              verify_timeout: 120
            YAML);

        $config = $this->loader->load($root . '/ngramx.yml');

        $this->assertSame(120, $config->docker->verifyTimeout);
    }

    public function test_it_defaults_default_team_to_gig(): void
    {
        $config = $this->loader->load(__DIR__ . '/../../fixtures/ngramx.yml');

        $this->assertSame('gig', $config->defaultTeam);
    }

    public function test_it_parses_and_lowercases_default_team(): void
    {
        $root = $this->makeTempDir();
        file_put_contents($root . '/ngramx.yml', <<<YAML
            version: "1.0"
            docker:
              compose_file: "docker-compose.yml"
              primary_service: "app"
              app_url: "http://localhost:8080"
            default_team: "COR"
            YAML);

        $config = $this->loader->load($root . '/ngramx.yml');

        $this->assertSame('cor', $config->defaultTeam);
    }

    public function test_it_rejects_a_non_alphabetic_default_team(): void
    {
        $root = $this->makeTempDir();
        file_put_contents($root . '/ngramx.yml', <<<YAML
            version: "1.0"
            docker:
              compose_file: "docker-compose.yml"
              primary_service: "app"
              app_url: "http://localhost:8080"
            default_team: "gig-1"
            YAML);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('default_team');

        $this->loader->load($root . '/ngramx.yml');
    }

    public function test_it_parses_command_definitions(): void
    {
        $config = $this->loader->load(__DIR__ . '/../../fixtures/ngramx.yml');

        $initCommand = $config->setup->initialize[0];
        $this->assertEquals("echo 'Initialize command executed'", $initCommand->command);
        $this->assertEquals('Test initialize command', $initCommand->description);
        $this->assertEquals(120, $initCommand->timeout);
    }

    public function test_it_parses_custom_commands(): void
    {
        $config = $this->loader->load(__DIR__ . '/../../fixtures/ngramx.yml');

        $this->assertArrayHasKey('test', $config->commands);
        $testCommand = $config->commands['test'];
        $this->assertEquals("echo 'Running tests'", $testCommand->command);
        $this->assertEquals('Run test suite', $testCommand->description);
    }

    public function test_it_loads_default_secrets_config_when_not_specified(): void
    {
        $config = $this->loader->load(__DIR__ . '/../../fixtures/ngramx.yml');

        $this->assertCount(1, $config->secrets->providers);
        $this->assertSame('env', $config->secrets->providers[0]->provider);
        $this->assertEmpty($config->secrets->providers[0]->required);
    }

    public function test_it_parses_single_command_into_commands_list(): void
    {
        $config = $this->loader->load(__DIR__ . '/../../fixtures/ngramx-parallel.yml');

        $single = $config->commands['single'];
        $this->assertSame('echo single', $single->command);
        $this->assertSame(['echo single'], $single->commands);
        $this->assertFalse($single->isParallel());
    }

    public function test_it_parses_parallel_command_list(): void
    {
        $config = $this->loader->load(__DIR__ . '/../../fixtures/ngramx-parallel.yml');

        $validate = $config->commands['validate'];
        $this->assertCount(3, $validate->commands);
        $this->assertSame('composer validate --strict', $validate->commands[0]);
        $this->assertSame('vendor/bin/phpstan analyse src', $validate->commands[1]);
        $this->assertSame('vendor/bin/phpunit', $validate->commands[2]);
        $this->assertTrue($validate->isParallel());
        $this->assertStringContainsString('composer validate --strict', $validate->command);
        $this->assertStringContainsString(' & ', $validate->command);
        $this->assertSame(180, $validate->timeout);
    }

    public function test_parallel_list_defaults_to_concurrent(): void
    {
        $config = $this->loader->load(__DIR__ . '/../../fixtures/ngramx-parallel.yml');

        $validate = $config->commands['validate'];
        $this->assertTrue($validate->parallel);
        $this->assertTrue($validate->isParallel());
        $this->assertFalse($validate->isSequentialList());
    }

    public function test_it_parses_sequential_command_list(): void
    {
        $config = $this->loader->load(__DIR__ . '/../../fixtures/ngramx-parallel.yml');

        $fresh = $config->commands['fresh'];
        $this->assertCount(3, $fresh->commands);
        $this->assertFalse($fresh->parallel);
        $this->assertFalse($fresh->isParallel());
        $this->assertTrue($fresh->isSequentialList());
        // Sequential lists render with `&&` to mirror stop-on-failure shell semantics.
        $this->assertStringContainsString(' && ', $fresh->command);
        $this->assertStringNotContainsString(' & php', $fresh->command);
        $this->assertSame(300, $fresh->timeout);
    }

    public function test_single_command_is_neither_parallel_nor_sequential_list(): void
    {
        $config = $this->loader->load(__DIR__ . '/../../fixtures/ngramx-parallel.yml');

        $single = $config->commands['single'];
        $this->assertFalse($single->isParallel());
        $this->assertFalse($single->isSequentialList());
    }

    public function test_it_loads_secrets_config(): void
    {
        $config = $this->loader->load(__DIR__ . '/../../fixtures/ngramx-with-secrets.yml');

        $this->assertCount(1, $config->secrets->providers);
        $this->assertSame('env', $config->secrets->providers[0]->provider);
        $this->assertCount(2, $config->secrets->providers[0]->required);
        $this->assertEquals('NOVA_ACCOUNT_EMAIL', $config->secrets->providers[0]->required[0]);
        $this->assertEquals('NOVA_LICENSE_KEY', $config->secrets->providers[0]->required[1]);
    }

    public function test_it_loads_multiple_secrets_providers(): void
    {
        $config = $this->loader->load(__DIR__ . '/../../fixtures/ngramx-with-multi-secrets.yml');

        $this->assertCount(2, $config->secrets->providers);
        $this->assertSame('.env', $config->secrets->providers[0]->provider);
        $this->assertSame(['APP_KEY', 'DB_PASSWORD'], $config->secrets->providers[0]->required);
        $this->assertSame('env', $config->secrets->providers[1]->provider);
        $this->assertSame(['NOVA_ACCOUNT_EMAIL', 'NOVA_LICENSE_KEY'], $config->secrets->providers[1]->required);
    }

    public function test_find_config_file_returns_config_in_current_directory(): void
    {
        $root = $this->makeTempDir();
        file_put_contents($root . '/ngramx.yml', "version: '1.0'\n");

        $this->inDirectory($root, function () use ($root): void {
            $this->assertSame($root . '/ngramx.yml', $this->loader->findConfigFile());
        });
    }

    public function test_find_config_file_walks_up_to_a_parent_directory(): void
    {
        $root = $this->makeTempDir();
        file_put_contents($root . '/ngramx.yml', "version: '1.0'\n");
        $nested = $root . '/src/app';
        mkdir($nested, 0755, true);

        $this->inDirectory($nested, function () use ($root): void {
            $this->assertSame($root . '/ngramx.yml', $this->loader->findConfigFile());
        });
    }

    public function test_find_config_file_does_not_escape_the_repository_boundary(): void
    {
        // Parent repo carries the config; a linked worktree lives inside it and
        // its root has a `.git` pointer file but does NOT track ngramx.yml.
        // Resolution must stop at the worktree boundary instead of inheriting
        // the parent's config (which would collide container names).
        $parent = $this->makeTempDir();
        file_put_contents($parent . '/ngramx.yml', "version: '1.0'\n");

        $worktree = $parent . '/.ngramx/worktrees/ticket';
        mkdir($worktree, 0755, true);
        file_put_contents($worktree . '/.git', "gitdir: {$parent}/.git/worktrees/ticket\n");

        $this->inDirectory($worktree, function (): void {
            $this->expectException(ConfigException::class);
            $this->expectExceptionMessage('ngramx.yml not found');
            $this->loader->findConfigFile();
        });
    }

    public function test_find_config_file_finds_config_inside_the_worktree(): void
    {
        // When the worktree DOES carry its own ngramx.yml, it is used directly
        // rather than the parent's — even though both exist.
        $parent = $this->makeTempDir();
        file_put_contents($parent . '/ngramx.yml', "version: 'parent'\n");

        $worktree = $parent . '/.ngramx/worktrees/ticket';
        mkdir($worktree, 0755, true);
        file_put_contents($worktree . '/.git', "gitdir: {$parent}/.git/worktrees/ticket\n");
        file_put_contents($worktree . '/ngramx.yml', "version: 'worktree'\n");

        $this->inDirectory($worktree, function () use ($worktree): void {
            $this->assertSame($worktree . '/ngramx.yml', $this->loader->findConfigFile());
        });
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/ngramx-config-test-' . uniqid('', true);
        mkdir($dir, 0755, true);
        // Resolve symlinks (macOS /tmp -> /private/tmp) so path assertions match
        // what getcwd() reports from inside the directory.
        $real = realpath($dir);
        $this->tempDirs[] = $real !== false ? $real : $dir;

        return $real !== false ? $real : $dir;
    }

    private function inDirectory(string $dir, callable $callback): void
    {
        $original = getcwd();
        chdir($dir);

        try {
            $callback();
        } finally {
            if ($original !== false) {
                chdir($original);
            }
        }
    }

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->tempDirs) as $dir) {
            $this->removeDirectory($dir);
        }
        $this->tempDirs = [];
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
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
