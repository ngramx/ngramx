<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Command;

use Ngramx\Command\SecureCommand;
use Ngramx\Config\ConfigLoader;
use Ngramx\Config\Exception\ConfigException;
use Ngramx\Config\Schema\DockerConfig;
use Ngramx\Config\Schema\N8nConfig;
use Ngramx\Config\Schema\NgramxConfig;
use Ngramx\Config\Schema\SetupConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class SecureCommandTest extends TestCase
{
    private ConfigLoader $configLoader;

    protected function setUp(): void
    {
        $this->configLoader = $this->createMock(ConfigLoader::class);
    }

    public function test_command_is_configured_correctly(): void
    {
        $command = new SecureCommand($this->configLoader);

        $this->assertSame('secure', $command->getName());
        $this->assertSame('Generate browser-trusted SSL certificates using mkcert', $command->getDescription());
    }

    public function test_it_fails_when_config_not_found(): void
    {
        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willThrowException(new ConfigException('ngramx.yml not found'));

        $command = new SecureCommand($this->configLoader);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Failed to load configuration', $tester->getDisplay());
    }

    public function test_it_extracts_hostname_from_https_url(): void
    {
        $command = new TestableSecureCommand($this->configLoader);

        $this->assertSame('myapp.localhost', $command->testExtractHostname('https://myapp.localhost'));
    }

    public function test_it_extracts_hostname_from_http_url(): void
    {
        $command = new TestableSecureCommand($this->configLoader);

        $this->assertSame('myapp.localhost', $command->testExtractHostname('http://myapp.localhost'));
    }

    public function test_it_returns_null_for_invalid_url(): void
    {
        $command = new TestableSecureCommand($this->configLoader);

        $this->assertNull($command->testExtractHostname('not-a-url'));
    }

    public function test_it_extracts_hostname_ignoring_port(): void
    {
        $command = new TestableSecureCommand($this->configLoader);

        $this->assertSame('myapp.localhost', $command->testExtractHostname('https://myapp.localhost:8443'));
    }

    public function test_default_ssl_path_is_used(): void
    {
        $config = $this->createMockConfig('https://myapp.localhost');

        $this->assertSame('docker/nginx/ssl', $config->docker->sslPath);
    }

    public function test_custom_ssl_path_is_used(): void
    {
        $dockerConfig = new DockerConfig(
            composeFile: 'docker-compose.yml',
            primaryService: 'app',
            appUrl: 'https://myapp.localhost',
            waitFor: [],
            sslPath: 'custom/ssl/path',
        );

        $this->assertSame('custom/ssl/path', $dockerConfig->sslPath);
    }

    private function createMockConfig(string $appUrl): NgramxConfig
    {
        $dockerConfig = new DockerConfig(
            composeFile: 'docker-compose.yml',
            primaryService: 'app',
            appUrl: $appUrl,
            waitFor: [],
        );

        $setupConfig = new SetupConfig(
            preStart: [],
            initialize: [],
        );

        $n8nConfig = new N8nConfig(
            workflowsDir: './.n8n',
        );

        return new NgramxConfig(
            version: '1.0',
            docker: $dockerConfig,
            setup: $setupConfig,
            n8n: $n8nConfig,
            commands: [],
        );
    }
}

/**
 * Exposes protected methods for testing.
 */
class TestableSecureCommand extends SecureCommand
{
    public function testExtractHostname(string $appUrl): ?string
    {
        $reflection = new \ReflectionMethod(SecureCommand::class, 'extractHostname');

        return $reflection->invoke($this, $appUrl);
    }
}
