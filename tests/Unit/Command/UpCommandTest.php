<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Command;

use Ngramx\Caddy\CaddyService;
use Ngramx\Command\UpCommand;
use Ngramx\Config\ConfigLoader;
use Ngramx\Config\LockFile;
use Ngramx\Config\LockFileData;
use Ngramx\Config\Schema\DockerConfig;
use Ngramx\Config\Schema\N8nConfig;
use Ngramx\Config\Schema\NgramxConfig;
use Ngramx\Config\Schema\SetupConfig;
use Ngramx\Docker\ComposeOverrideGenerator;
use Ngramx\Docker\DockerCompose;
use Ngramx\Docker\NamespaceResolver;
use Ngramx\Docker\PortOffsetManager;
use Ngramx\Herd\HerdService;
use Ngramx\Orchestrator\SetupOrchestrator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class UpCommandTest extends TestCase
{
    private ConfigLoader $configLoader;
    private SetupOrchestrator $setupOrchestrator;
    private LockFile $lockFile;
    private NamespaceResolver $namespaceResolver;
    private PortOffsetManager $portOffsetManager;
    private ComposeOverrideGenerator $overrideGenerator;
    private DockerCompose $dockerCompose;
    private HerdService $herdService;
    private CaddyService $caddyService;

    protected function setUp(): void
    {
        $this->configLoader = $this->createMock(ConfigLoader::class);
        $this->setupOrchestrator = $this->createMock(SetupOrchestrator::class);
        $this->lockFile = $this->createMock(LockFile::class);
        $this->namespaceResolver = $this->createMock(NamespaceResolver::class);
        $this->portOffsetManager = $this->createMock(PortOffsetManager::class);
        $this->overrideGenerator = $this->createMock(ComposeOverrideGenerator::class);
        $this->dockerCompose = $this->createMock(DockerCompose::class);
        $this->herdService = $this->createMock(HerdService::class);
        $this->caddyService = $this->createMock(CaddyService::class);

        $this->dockerCompose->method('isDockerRunning')->willReturn(true);
    }

    public function test_command_is_configured_correctly(): void
    {
        $command = $this->createCommand();

        $this->assertSame('up', $command->getName());
        $this->assertSame('Set up the development environment', $command->getDescription());

        $definition = $command->getDefinition();
        $this->assertTrue($definition->hasOption('namespace'));
        $this->assertTrue($definition->hasOption('port-offset'));
        $this->assertTrue($definition->hasOption('avoid-conflicts'));
        $this->assertTrue($definition->hasOption('no-host-mapping'));
        $this->assertTrue($definition->hasOption('no-wait'));
        $this->assertTrue($definition->hasOption('skip-init'));
        $this->assertTrue($definition->hasOption('stop-herd'));
        $this->assertTrue($definition->hasOption('rebuild'));
    }

    public function test_it_fails_when_docker_is_not_running(): void
    {
        $this->dockerCompose = $this->createMock(DockerCompose::class);
        $this->dockerCompose->method('isDockerRunning')->willReturn(false);

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('You must start Docker before running ngramx up', $tester->getDisplay());
    }

    public function test_it_prevents_duplicate_instances(): void
    {
        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(true);

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('already running', $tester->getDisplay());
    }

    public function test_it_runs_default_mode_without_namespace_or_offset(): void
    {
        $config = $this->createMockConfig();

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/ngramx.yml');

        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        // No namespace resolution in default mode
        $this->namespaceResolver->expects($this->never())
            ->method('deriveFromDirectory');

        // No override generation in default mode
        $this->overrideGenerator->expects($this->never())
            ->method('generate');

        $this->setupOrchestrator->expects($this->once())
            ->method('setup')
            ->with(
                $config,
                false,
                false,
                null,
                0
            )
            ->willReturn([
                'time' => 1.5,
                'namespace' => '',
                'port_offset' => 0,
            ]);

        // No lock file in default mode
        $this->lockFile->expects($this->never())
            ->method('write');

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
    }

    public function test_it_uses_explicit_namespace(): void
    {
        $config = $this->createMockConfig();

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/ngramx.yml');

        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        $this->namespaceResolver->expects($this->once())
            ->method('validate')
            ->with('custom-namespace');

        // Override file should be generated with namespace prefix
        $this->overrideGenerator->expects($this->once())
            ->method('generate')
            ->with('docker-compose.yml', 0, 'custom-namespace', false);

        $this->setupOrchestrator->expects($this->once())
            ->method('setup')
            ->with(
                $config,
                false,
                false,
                'custom-namespace',
                0,
                false
            )
            ->willReturn([
                'time' => 1.5,
                'namespace' => 'custom-namespace',
                'port_offset' => 0,
            ]);

        // Lock file should be written when using namespace (even with port offset 0)
        $this->lockFile->expects($this->once())
            ->method('write')
            ->with($this->callback(function (LockFileData $data) {
                return $data->namespace === 'custom-namespace' && $data->portOffset === null;
            }));

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--namespace' => 'custom-namespace']);

        $this->assertSame(0, $exitCode);
    }

    public function test_it_uses_explicit_port_offset(): void
    {
        $config = $this->createMockConfig();

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/ngramx.yml');

        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        // No explicit namespace, so default mode (no namespace)
        $this->namespaceResolver->expects($this->never())
            ->method('deriveFromDirectory');

        $this->overrideGenerator->expects($this->once())
            ->method('generate')
            ->with('docker-compose.yml', 1000, null, false);

        $this->setupOrchestrator->expects($this->once())
            ->method('setup')
            ->with(
                $config,
                false,
                false,
                null,
                1000,
                false
            )
            ->willReturn([
                'time' => 1.5,
                'namespace' => '',
                'port_offset' => 1000,
            ]);

        $this->lockFile->expects($this->once())
            ->method('write')
            ->with($this->callback(function (LockFileData $data) {
                return $data->portOffset === 1000;
            }));

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--port-offset' => '1000']);

        $this->assertSame(0, $exitCode);
    }

    public function test_it_auto_allocates_with_avoid_conflicts(): void
    {
        $config = $this->createMockConfig();

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/ngramx.yml');

        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        $this->namespaceResolver->expects($this->once())
            ->method('deriveFromDirectory')
            ->willReturn('ngramx-test-project');

        $this->portOffsetManager->expects($this->once())
            ->method('extractBasePorts')
            ->willReturn([80, 443]);

        $this->portOffsetManager->expects($this->once())
            ->method('findAvailableOffset')
            ->with([80, 443])
            ->willReturn(8000);

        // Should generate override with both port offset and namespace prefix
        $this->overrideGenerator->expects($this->once())
            ->method('generate')
            ->with('docker-compose.yml', 8000, 'ngramx-test-project', false);

        $this->setupOrchestrator->expects($this->once())
            ->method('setup')
            ->willReturn([
                'time' => 1.5,
                'namespace' => 'ngramx-test-project',
                'port_offset' => 8000,
            ]);

        $this->lockFile->expects($this->once())
            ->method('write');

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--avoid-conflicts' => true]);

        $this->assertSame(0, $exitCode);
    }

    public function test_it_does_not_generate_override_in_default_mode(): void
    {
        $config = $this->createMockConfig();

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/ngramx.yml');

        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        // No namespace derivation in default mode
        $this->namespaceResolver->expects($this->never())
            ->method('deriveFromDirectory');

        // No override generation in default mode
        $this->overrideGenerator->expects($this->never())
            ->method('generate');

        $this->setupOrchestrator->expects($this->once())
            ->method('setup')
            ->with($config, false, false, null, 0, false)
            ->willReturn([
                'time' => 1.5,
                'namespace' => '',
                'port_offset' => 0,
            ]);

        // No lock file in default mode
        $this->lockFile->expects($this->never())
            ->method('write');

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
    }

    public function test_it_disables_host_port_mapping_with_no_host_mapping_flag(): void
    {
        $config = $this->createMockConfig();

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/ngramx.yml');

        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        // Port offset should not be scanned when no-host-mapping is set
        $this->portOffsetManager->expects($this->never())
            ->method('extractBasePorts');

        $this->portOffsetManager->expects($this->never())
            ->method('findAvailableOffset');

        // Override file should be generated with noHostMapping=true
        $this->overrideGenerator->expects($this->once())
            ->method('generate')
            ->with('docker-compose.yml', 0, null, true);

        $this->setupOrchestrator->expects($this->once())
            ->method('setup')
            ->with(
                $config,
                false,
                false,
                null,
                0,
                false
            )
            ->willReturn([
                'time' => 1.5,
                'namespace' => '',
                'port_offset' => 0,
            ]);

        $this->lockFile->expects($this->once())
            ->method('write')
            ->with($this->callback(function (LockFileData $data) {
                return $data->noHostMapping === true && $data->portOffset === null;
            }));

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--no-host-mapping' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Host port mapping disabled', $tester->getDisplay());
    }

    public function test_it_combines_no_host_mapping_with_avoid_conflicts_namespace(): void
    {
        $config = $this->createMockConfig();

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/ngramx.yml');

        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        $this->namespaceResolver->expects($this->once())
            ->method('deriveFromDirectory')
            ->willReturn('ngramx-test-project');

        // Port offset should not be scanned when no-host-mapping is set
        $this->portOffsetManager->expects($this->never())
            ->method('extractBasePorts');

        // Override file should be generated with namespace and noHostMapping=true
        $this->overrideGenerator->expects($this->once())
            ->method('generate')
            ->with('docker-compose.yml', 0, 'ngramx-test-project', true);

        $this->setupOrchestrator->expects($this->once())
            ->method('setup')
            ->willReturn([
                'time' => 1.5,
                'namespace' => 'ngramx-test-project',
                'port_offset' => 0,
            ]);

        $this->lockFile->expects($this->once())
            ->method('write')
            ->with($this->callback(function (LockFileData $data) {
                return $data->namespace === 'ngramx-test-project' && $data->noHostMapping === true;
            }));

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--avoid-conflicts' => true, '--no-host-mapping' => true]);

        $this->assertSame(0, $exitCode);
    }

    public function test_it_stops_herd_when_stop_herd_flag_is_set(): void
    {
        $config = $this->createMockConfig();

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/ngramx.yml');

        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        $this->herdService->expects($this->once())
            ->method('isInstalled')
            ->willReturn(true);

        $this->herdService->expects($this->once())
            ->method('stop');

        $this->caddyService->expects($this->once())
            ->method('stopListenersOnPorts')
            ->with([80, 443])
            ->willReturn(0);

        $this->setupOrchestrator->expects($this->once())
            ->method('setup')
            ->willReturn(['time' => 1.5, 'namespace' => '', 'port_offset' => 0]);

        $this->lockFile->expects($this->once())
            ->method('write')
            ->with($this->callback(function (LockFileData $data) {
                return $data->herdStopped === true;
            }));

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--stop-herd' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Stopping Herd services', $tester->getDisplay());
        $this->assertStringContainsString('Herd services stopped', $tester->getDisplay());
    }

    public function test_it_warns_when_herd_not_installed_with_stop_herd_flag(): void
    {
        $config = $this->createMockConfig();

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/ngramx.yml');

        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        $this->herdService->expects($this->once())
            ->method('isInstalled')
            ->willReturn(false);

        $this->herdService->expects($this->never())
            ->method('stop');

        $this->caddyService->expects($this->once())
            ->method('stopListenersOnPorts')
            ->with([80, 443])
            ->willReturn(0);

        $this->setupOrchestrator->expects($this->once())
            ->method('setup')
            ->willReturn(['time' => 1.5, 'namespace' => '', 'port_offset' => 0]);

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--stop-herd' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Herd is not installed', $tester->getDisplay());
    }

    public function test_it_sets_caddy_stopped_in_lock_when_caddy_was_signalled(): void
    {
        $config = $this->createMockConfig();

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/ngramx.yml');

        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        $this->herdService->expects($this->once())
            ->method('isInstalled')
            ->willReturn(true);

        $this->herdService->expects($this->once())
            ->method('stop');

        $this->caddyService->expects($this->once())
            ->method('stopListenersOnPorts')
            ->with([80, 443])
            ->willReturn(2);

        $this->setupOrchestrator->expects($this->once())
            ->method('setup')
            ->willReturn(['time' => 1.5, 'namespace' => '', 'port_offset' => 0]);

        $this->lockFile->expects($this->once())
            ->method('write')
            ->with($this->callback(function (LockFileData $data) {
                return $data->herdStopped === true && $data->caddyStopped === true;
            }));

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--stop-herd' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Stopped 2 Caddy processes', $tester->getDisplay());
    }

    public function test_it_does_not_stop_herd_without_flag(): void
    {
        $config = $this->createMockConfig();

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/ngramx.yml');

        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        $this->herdService->expects($this->never())
            ->method('isInstalled');

        $this->herdService->expects($this->never())
            ->method('stop');

        $this->caddyService->expects($this->never())
            ->method('stopListenersOnPorts');

        $this->setupOrchestrator->expects($this->once())
            ->method('setup')
            ->willReturn(['time' => 1.5, 'namespace' => '', 'port_offset' => 0]);

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
    }

    private function createCommand(): UpCommand
    {
        return new UpCommand(
            $this->configLoader,
            $this->setupOrchestrator,
            $this->lockFile,
            $this->namespaceResolver,
            $this->portOffsetManager,
            $this->overrideGenerator,
            $this->dockerCompose,
            $this->herdService,
            $this->caddyService
        );
    }

    private function createMockConfig(): NgramxConfig
    {
        $dockerConfig = new DockerConfig(
            composeFile: 'docker-compose.yml',
            primaryService: 'app',
            appUrl: 'http://localhost:80',
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
