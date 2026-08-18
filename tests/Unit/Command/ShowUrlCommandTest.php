<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Command;

use Ngramx\Command\ShowUrlCommand;
use Ngramx\Config\ConfigLoader;
use Ngramx\Config\LockFile;
use Ngramx\Config\LockFileData;
use Ngramx\Config\Schema\DockerConfig;
use Ngramx\Config\Schema\N8nConfig;
use Ngramx\Config\Schema\NgramxConfig;
use Ngramx\Config\Schema\SetupConfig;
use Ngramx\Docker\PortOffsetManager;
use Ngramx\Worktree\WorktreeUrlResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ShowUrlCommandTest extends TestCase
{
    private ConfigLoader $configLoader;
    private LockFile $lockFile;
    private PortOffsetManager $portOffsetManager;
    private WorktreeUrlResolver $worktreeUrlResolver;

    protected function setUp(): void
    {
        $this->configLoader = $this->createMock(ConfigLoader::class);
        $this->lockFile = $this->createMock(LockFile::class);
        $this->portOffsetManager = $this->createMock(PortOffsetManager::class);
        $this->worktreeUrlResolver = $this->createMock(WorktreeUrlResolver::class);
    }

    public function test_command_is_configured_correctly(): void
    {
        $command = $this->createCommand();

        $this->assertSame('show-url', $command->getName());
        $this->assertSame(['url'], $command->getAliases());
        $this->assertSame('Display the URL for the development environment', $command->getDescription());
    }

    public function test_it_outputs_url_with_base_port(): void
    {
        $config = $this->createMockConfig('http://localhost');

        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/ngramx.yml');

        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->portOffsetManager->expects($this->once())
            ->method('getPrimaryServicePort')
            ->with('docker-compose.yml', 'app')
            ->willReturn(80);

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        // Port 80 is http's own default — printing it back is noise.
        $this->assertSame("http://localhost\n", $tester->getDisplay());
    }

    public function test_it_applies_port_offset_from_lock_file(): void
    {
        $config = $this->createMockConfig('http://localhost');

        $lockData = new LockFileData(
            namespace: 'ngramx-agent-1',
            portOffset: 8000,
            startedAt: '2025-01-07T10:00:00+00:00'
        );

        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/ngramx.yml');

        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(true);

        $this->lockFile->expects($this->once())
            ->method('read')
            ->willReturn($lockData);

        $this->portOffsetManager->expects($this->once())
            ->method('getPrimaryServicePort')
            ->with('docker-compose.yml', 'app')
            ->willReturn(80);

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertSame("http://localhost:8080\n", $tester->getDisplay());
    }

    public function test_it_applies_port_map_from_lock_file(): void
    {
        $config = $this->createMockConfig('http://localhost');

        $lockData = new LockFileData(
            namespace: null,
            portOffset: null,
            startedAt: '2025-01-07T10:00:00+00:00',
            portMap: [80 => 180, 5432 => 5532],
        );

        $this->configLoader->expects($this->any())->method('findConfigFile')->willReturn('/path/to/ngramx.yml');
        $this->configLoader->expects($this->any())->method('load')->willReturn($config);
        $this->lockFile->expects($this->any())->method('exists')->willReturn(true);
        $this->lockFile->expects($this->any())->method('read')->willReturn($lockData);

        $this->portOffsetManager->expects($this->once())
            ->method('getPrimaryServicePort')
            ->with('docker-compose.yml', 'app')
            ->willReturn(80);

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertSame("http://localhost:180\n", $tester->getDisplay());
    }

    public function test_it_ignores_port_map_when_primary_port_is_not_mapped(): void
    {
        $config = $this->createMockConfig('http://localhost');

        $lockData = new LockFileData(
            namespace: null,
            portOffset: null,
            startedAt: '2025-01-07T10:00:00+00:00',
            portMap: [5432 => 5532],
        );

        $this->configLoader->expects($this->any())->method('findConfigFile')->willReturn('/path/to/ngramx.yml');
        $this->configLoader->expects($this->any())->method('load')->willReturn($config);
        $this->lockFile->expects($this->any())->method('exists')->willReturn(true);
        $this->lockFile->expects($this->any())->method('read')->willReturn($lockData);

        $this->portOffsetManager->expects($this->any())->method('getPrimaryServicePort')->willReturn(80);

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertSame("http://localhost\n", $tester->getDisplay());
    }

    public function test_it_outputs_app_url_when_no_port_exposed(): void
    {
        $config = $this->createMockConfig('http://localhost');

        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/ngramx.yml');

        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->portOffsetManager->expects($this->once())
            ->method('getPrimaryServicePort')
            ->with('docker-compose.yml', 'app')
            ->willReturn(null);

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertSame("http://localhost\n", $tester->getDisplay());
    }

    public function test_it_outputs_internal_url_when_no_host_mapping_with_namespace(): void
    {
        // Create a temporary docker-compose.yml for the test
        $tempDir = sys_get_temp_dir() . '/ngramx-test-' . uniqid();
        mkdir($tempDir, 0755, true);
        $composeFile = $tempDir . '/docker-compose.yml';
        file_put_contents(
            $composeFile,
            <<<YAML
services:
  nginx:
    image: nginx:alpine
    container_name: ngramx_nginx
    ports:
      - "80:80"
  app:
    image: php:8.2
    container_name: ngramx_app
YAML
        );

        try {
            $config = $this->createMockConfig('http://localhost', $composeFile);

            $lockData = new LockFileData(
                namespace: 'ngramx-agent-1-project',
                portOffset: null,
                startedAt: '2025-01-07T10:00:00+00:00',
                noHostMapping: true
            );

            $this->configLoader->expects($this->once())
                ->method('findConfigFile')
                ->willReturn('/path/to/ngramx.yml');

            $this->configLoader->expects($this->once())
                ->method('load')
                ->willReturn($config);

            $this->lockFile->expects($this->once())
                ->method('exists')
                ->willReturn(true);

            $this->lockFile->expects($this->once())
                ->method('read')
                ->willReturn($lockData);

            // Should not call getPrimaryServicePort when using internal URL
            $this->portOffsetManager->expects($this->never())
                ->method('getPrimaryServicePort');

            $command = $this->createCommand();
            $tester = new CommandTester($command);
            $exitCode = $tester->execute([]);

            $this->assertSame(0, $exitCode);
            $this->assertSame("http://ngramx-agent-1-project-ngramx_nginx:80\n", $tester->getDisplay());
        } finally {
            // Clean up
            unlink($composeFile);
            rmdir($tempDir);
        }
    }

    public function test_it_falls_back_to_app_url_when_no_host_mapping_but_no_namespace(): void
    {
        $config = $this->createMockConfig('http://localhost');

        $lockData = new LockFileData(
            namespace: null,
            portOffset: null,
            startedAt: '2025-01-07T10:00:00+00:00',
            noHostMapping: true
        );

        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/ngramx.yml');

        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(true);

        $this->lockFile->expects($this->once())
            ->method('read')
            ->willReturn($lockData);

        $this->portOffsetManager->expects($this->once())
            ->method('getPrimaryServicePort')
            ->with('docker-compose.yml', 'app')
            ->willReturn(null);

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertSame("http://localhost\n", $tester->getDisplay());
    }

    public function test_it_uses_the_web_port_published_by_a_non_primary_service(): void
    {
        // A Laravel-style stack: `app` runs PHP-FPM and publishes nothing,
        // while `nginx` publishes the web ports. Asking the primary service for
        // "its" port finds nothing, so the offset used to be dropped entirely
        // and the raw app_url was printed.
        $config = $this->createMockConfig('https://terrablock.localhost');

        $lockData = new LockFileData(
            namespace: 'ngramx-gig-2896-terrablock',
            portOffset: 8300,
            startedAt: '2025-01-07T10:00:00+00:00',
        );

        $this->configLoader->expects($this->any())->method('findConfigFile')->willReturn('/path/to/ngramx.yml');
        $this->configLoader->expects($this->any())->method('load')->willReturn($config);
        $this->lockFile->expects($this->any())->method('exists')->willReturn(true);
        $this->lockFile->expects($this->any())->method('read')->willReturn($lockData);

        $this->portOffsetManager->expects($this->once())
            ->method('findHostPortForInternalPort')
            ->with('docker-compose.yml', 443, 'app')
            ->willReturn(443);

        $this->portOffsetManager->expects($this->never())
            ->method('getPrimaryServicePort');

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertSame("https://terrablock.localhost:8743\n", $tester->getDisplay());
    }

    public function test_it_applies_the_offset_to_an_explicit_port_in_the_app_url(): void
    {
        $config = $this->createMockConfig('http://dev.hydra:8080');

        $lockData = new LockFileData(
            namespace: null,
            portOffset: 1000,
            startedAt: '2025-01-07T10:00:00+00:00',
        );

        $this->configLoader->expects($this->any())->method('findConfigFile')->willReturn('/path/to/ngramx.yml');
        $this->configLoader->expects($this->any())->method('load')->willReturn($config);
        $this->lockFile->expects($this->any())->method('exists')->willReturn(true);
        $this->lockFile->expects($this->any())->method('read')->willReturn($lockData);

        $this->portOffsetManager->expects($this->never())->method('findHostPortForInternalPort');
        $this->portOffsetManager->expects($this->never())->method('getPrimaryServicePort');

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertSame("http://dev.hydra:9080\n", $tester->getDisplay());
    }

    public function test_it_prefers_the_url_recorded_in_the_lock_file(): void
    {
        $config = $this->createMockConfig('https://terrablock.localhost');

        $lockData = new LockFileData(
            namespace: 'ngramx-gig-2896-terrablock',
            portOffset: 8300,
            startedAt: '2025-01-07T10:00:00+00:00',
            url: 'https://gig-2896-terrablock.localhost:8743',
        );

        $this->configLoader->expects($this->any())->method('findConfigFile')->willReturn('/path/to/ngramx.yml');
        $this->configLoader->expects($this->any())->method('load')->willReturn($config);
        $this->lockFile->expects($this->any())->method('exists')->willReturn(true);
        $this->lockFile->expects($this->any())->method('read')->willReturn($lockData);

        $this->portOffsetManager->expects($this->never())->method('findHostPortForInternalPort');
        $this->worktreeUrlResolver->expects($this->never())->method('resolve');

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertSame("https://gig-2896-terrablock.localhost:8743\n", $tester->getDisplay());
    }

    public function test_it_resolves_the_worktree_hostname_when_run_inside_a_worktree(): void
    {
        $config = $this->createMockConfig('https://terrablock.localhost');

        $lockData = new LockFileData(
            namespace: 'ngramx-gig-2896-terrablock',
            portOffset: 8300,
            startedAt: '2025-01-07T10:00:00+00:00',
        );

        $this->configLoader->expects($this->any())->method('findConfigFile')
            ->willReturn('/repo/.ngramx/worktrees/gig-2896-terrablock/ngramx.yml');
        $this->configLoader->expects($this->any())->method('load')->willReturn($config);
        $this->lockFile->expects($this->any())->method('exists')->willReturn(true);
        $this->lockFile->expects($this->any())->method('read')->willReturn($lockData);
        $this->portOffsetManager->expects($this->any())->method('findHostPortForInternalPort')->willReturn(443);

        $this->worktreeUrlResolver->expects($this->once())
            ->method('resolve')
            ->with('https://terrablock.localhost:8743', 'gig-2896-terrablock', 0)
            ->willReturn('https://gig-2896-terrablock.localhost:8743');

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertSame("https://gig-2896-terrablock.localhost:8743\n", $tester->getDisplay());
    }

    public function test_it_does_not_resolve_a_worktree_hostname_in_a_normal_checkout(): void
    {
        $config = $this->createMockConfig('https://terrablock.localhost');

        $this->configLoader->expects($this->any())->method('findConfigFile')->willReturn('/repo/ngramx.yml');
        $this->configLoader->expects($this->any())->method('load')->willReturn($config);
        $this->lockFile->expects($this->any())->method('exists')->willReturn(false);
        $this->portOffsetManager->expects($this->any())->method('findHostPortForInternalPort')->willReturn(443);

        $this->worktreeUrlResolver->expects($this->never())->method('resolve');

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertSame("https://terrablock.localhost\n", $tester->getDisplay());
    }

    private function createCommand(): ShowUrlCommand
    {
        return new ShowUrlCommand(
            $this->configLoader,
            $this->lockFile,
            $this->portOffsetManager,
            $this->worktreeUrlResolver
        );
    }

    private function createMockConfig(string $appUrl, string $composeFile = 'docker-compose.yml'): NgramxConfig
    {
        $dockerConfig = new DockerConfig(
            composeFile: $composeFile,
            primaryService: 'app',
            appUrl: $appUrl,
            waitFor: []
        );

        $setupConfig = new SetupConfig(
            preStart: [],
            initialize: []
        );

        $n8nConfig = new N8nConfig(
            workflowsDir: './.n8n'
        );

        return new NgramxConfig(
            version: '1.0',
            docker: $dockerConfig,
            setup: $setupConfig,
            n8n: $n8nConfig,
            commands: []
        );
    }
}
