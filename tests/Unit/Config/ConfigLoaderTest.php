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

        $this->assertEquals('env', $config->secrets->provider);
        $this->assertEmpty($config->secrets->required);
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

    public function test_it_loads_secrets_config(): void
    {
        $config = $this->loader->load(__DIR__ . '/../../fixtures/ngramx-with-secrets.yml');

        $this->assertEquals('env', $config->secrets->provider);
        $this->assertCount(2, $config->secrets->required);
        $this->assertEquals('NOVA_ACCOUNT_EMAIL', $config->secrets->required[0]);
        $this->assertEquals('NOVA_LICENSE_KEY', $config->secrets->required[1]);
    }
}
