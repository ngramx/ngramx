<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Command\N8n;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Ngramx\Command\N8n\ExportCommand;
use Ngramx\Config\ConfigLoader;
use Ngramx\Config\Exception\ConfigException;
use Ngramx\Config\Schema\DockerConfig;
use Ngramx\Config\Schema\N8nConfig;
use Ngramx\Config\Schema\NgramxConfig;
use Ngramx\Config\Schema\SetupConfig;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class ExportCommandTest extends TestCase
{
    use CommandTestTrait;

    protected ConfigLoader $configLoader;
    protected Client $httpClient;
    protected string $testDir;
    protected string $envPath;
    protected string $workflowsDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configLoader = $this->createMock(ConfigLoader::class);
        $this->httpClient = $this->createMock(Client::class);
        $this->testDir = sys_get_temp_dir() . '/ngramx_n8n_test_' . uniqid();
        mkdir($this->testDir, 0755, true);
        $this->envPath = $this->testDir . '/.env';
        $this->workflowsDir = $this->testDir . '/.n8n';
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (is_dir($this->testDir)) {
            $this->recursiveRemoveDirectory($this->testDir);
        }
    }

    // ==================== Command Configuration Tests ====================

    public function test_command_is_configured_correctly(): void
    {
        $command = new ExportCommand($this->configLoader, $this->httpClient);

        $this->assertSame('n8n:export', $command->getName());
        $this->assertSame('Export n8n workflows', $command->getDescription());

        $definition = $command->getDefinition();
        $this->assertTrue($definition->hasOption('force'));
        $this->assertTrue($definition->hasShortcut('f'));
        $this->assertFalse($definition->getOption('force')->isValueRequired());
    }

    // ==================== Helper Methods ====================

    private function createCommand(): ExportCommand
    {
        return new ExportCommand($this->configLoader, $this->httpClient);
    }

    // ==================== fetchWorkflowDetails() Tests ====================

    public function test_fetchWorkflowDetails_returns_decoded_json(): void
    {
        $command = $this->createCommand();
        $workflowData = ['id' => '1', 'name' => 'Test Workflow', 'nodes' => []];

        $response = $this->createMockResponseFromJson($workflowData, false);
        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $result = $this->invokeMethod($command, 'fetchWorkflowDetails', ['http://localhost/api/v1/workflows/1', []]);

        $this->assertIsArray($result);
        $this->assertSame('1', $result['id']);
        $this->assertSame('Test Workflow', $result['name']);
    }

    public function test_fetchWorkflowDetails_handles_invalid_json(): void
    {
        $command = $this->createCommand();
        $response = $this->createMockResponse('invalid json', false);
        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $this->expectException(\JsonException::class);

        $this->invokeMethod($command, 'fetchWorkflowDetails', ['http://localhost/api/v1/workflows/1', []]);
    }

    // ==================== formatWorkflowJson() Tests ====================

    public function test_formatWorkflowJson_formats_with_pretty_print(): void
    {
        $command = $this->createCommand();
        $data = ['id' => '1', 'name' => 'Test', 'nodes' => []];

        $result = $this->invokeMethod($command, 'formatWorkflowJson', [$data]);

        $this->assertIsString($result);
        $decoded = json_decode($result, true);
        $this->assertSame($data, $decoded);
        $this->assertStringContainsString("\n", $result); // Pretty print should have newlines
    }

    public function test_formatWorkflowJson_preserves_slashes(): void
    {
        $command = $this->createCommand();
        $data = ['url' => 'http://example.com/path'];

        $result = $this->invokeMethod($command, 'formatWorkflowJson', [$data]);

        $this->assertStringContainsString('http://example.com/path', $result);
        $this->assertStringNotContainsString('http:\/\/example.com\/path', $result);
    }

    // ==================== saveWorkflow() Tests ====================

    public function test_saveWorkflow_writes_file(): void
    {
        $command = $this->createCommand();
        $destFile = $this->workflowsDir . '/test.json';
        $jsonContent = '{"test": "data"}';

        mkdir($this->workflowsDir, 0755, true);
        $this->invokeMethod($command, 'saveWorkflow', [$destFile, $jsonContent]);

        $this->assertFileExists($destFile);
        $fileContent = file_get_contents($destFile);
        $this->assertIsString($fileContent);
        $this->assertSame($jsonContent, $fileContent);
    }

    // ==================== shouldSkipFile() Tests ====================

    public function test_shouldSkipFile_returns_true_when_file_exists_and_not_forced(): void
    {
        $command = $this->createCommand();
        $destFile = $this->workflowsDir . '/test.json';

        mkdir($this->workflowsDir, 0755, true);
        file_put_contents($destFile, '{}');

        $result = $this->invokeMethod($command, 'shouldSkipFile', [$destFile, false]);

        $this->assertTrue($result);
    }

    public function test_shouldSkipFile_returns_false_when_file_exists_and_forced(): void
    {
        $command = $this->createCommand();
        $destFile = $this->workflowsDir . '/test.json';

        mkdir($this->workflowsDir, 0755, true);
        file_put_contents($destFile, '{}');

        $result = $this->invokeMethod($command, 'shouldSkipFile', [$destFile, true]);

        $this->assertFalse($result);
    }

    public function test_shouldSkipFile_returns_false_when_file_not_exists(): void
    {
        $command = $this->createCommand();
        $destFile = $this->workflowsDir . '/nonexistent.json';

        $result = $this->invokeMethod($command, 'shouldSkipFile', [$destFile, false]);

        $this->assertFalse($result);
    }


    // ==================== ensureDirectoryExists() Tests ====================

    public function test_ensureDirectoryExists_creates_directory_when_missing(): void
    {
        $command = $this->createCommand();
        $newDir = $this->testDir . '/new-dir';

        $this->assertDirectoryDoesNotExist($newDir);
        $this->invokeMethod($command, 'ensureDirectoryExists', [$newDir]);
        $this->assertDirectoryExists($newDir);
    }

    public function test_ensureDirectoryExists_does_nothing_when_directory_exists(): void
    {
        $command = $this->createCommand();
        mkdir($this->workflowsDir, 0755, true);

        $this->assertDirectoryExists($this->workflowsDir);
        $this->invokeMethod($command, 'ensureDirectoryExists', [$this->workflowsDir]);
        $this->assertDirectoryExists($this->workflowsDir);
    }

    // ==================== performExport() Integration Tests ====================

    public function test_performExport_successfully_exports_workflows(): void
    {
        $env = [
            'NGRAMX_N8N_HOST' => 'http://localhost',
            'NGRAMX_N8N_PORT' => '5678',
            'NGRAMX_N8N_API_KEY' => 'test-key',
        ];

        $workflowsResponse = $this->createMockResponseFromJson([
            'data' => [
                ['id' => '1', 'name' => 'Workflow 1'],
                ['id' => '2', 'name' => 'Workflow 2'],
            ],
        ]);

        $workflow1Response = $this->createMockResponseFromJson(['id' => '1', 'name' => 'Workflow 1', 'nodes' => []], false);
        $workflow2Response = $this->createMockResponseFromJson(['id' => '2', 'name' => 'Workflow 2', 'nodes' => []], false);

        $this->httpClient->expects($this->exactly(3))
            ->method('request')
            ->willReturnCallback(function ($method, $uri) use ($workflowsResponse, $workflow1Response, $workflow2Response) {
                if (preg_match('#/api/v1/workflows$#', $uri)) {
                    return $workflowsResponse;
                } elseif (str_contains($uri, '/api/v1/workflows/1')) {
                    return $workflow1Response;
                } elseif (str_contains($uri, '/api/v1/workflows/2')) {
                    return $workflow2Response;
                }
                throw new \RuntimeException('Unexpected URI: ' . $uri);
            });

        $formatter = $this->createMock(\Ngramx\Output\OutputFormatter::class);
        $command = $this->createCommand();
        mkdir($this->workflowsDir, 0755, true);

        $skipped = $this->invokeMethod($command, 'performExport', [$env, $this->workflowsDir, false, $formatter]);

        $this->assertFalse($skipped);
        $this->assertFileExists($this->workflowsDir . '/Workflow 1.json');
        $this->assertFileExists($this->workflowsDir . '/Workflow 2.json');
    }

    public function test_performExport_skips_existing_files_without_force(): void
    {
        $env = [
            'NGRAMX_N8N_HOST' => 'http://localhost',
            'NGRAMX_N8N_PORT' => '5678',
            'NGRAMX_N8N_API_KEY' => 'test-key',
        ];

        mkdir($this->workflowsDir, 0755, true);
        file_put_contents($this->workflowsDir . '/Test Workflow.json', '{"existing": "content"}');

        $workflowsResponse = $this->createMockResponseFromJson([
            'data' => [
                ['id' => '1', 'name' => 'Test Workflow'],
            ],
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($workflowsResponse);

        $formatter = $this->createMock(\Ngramx\Output\OutputFormatter::class);
        $formatter->expects($this->once())
            ->method('info')
            ->with($this->stringContains('already exists'));

        $command = $this->createCommand();
        $skipped = $this->invokeMethod($command, 'performExport', [$env, $this->workflowsDir, false, $formatter]);

        $this->assertTrue($skipped);
        $fileContent = file_get_contents($this->workflowsDir . '/Test Workflow.json');
        $this->assertIsString($fileContent);
        $this->assertSame('{"existing": "content"}', $fileContent);
    }

    public function test_performExport_overwrites_existing_files_with_force(): void
    {
        $env = [
            'NGRAMX_N8N_HOST' => 'http://localhost',
            'NGRAMX_N8N_PORT' => '5678',
            'NGRAMX_N8N_API_KEY' => 'test-key',
        ];

        mkdir($this->workflowsDir, 0755, true);
        file_put_contents($this->workflowsDir . '/Test Workflow.json', '{"old": "content"}');

        $workflowsResponse = $this->createMockResponseFromJson([
            'data' => [
                ['id' => '1', 'name' => 'Test Workflow'],
            ],
        ]);

        $workflowResponse = $this->createMockResponseFromJson(['id' => '1', 'name' => 'Test Workflow', 'nodes' => []], false);

        $this->httpClient->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function ($method, $uri) use ($workflowsResponse, $workflowResponse) {
                if (preg_match('#/api/v1/workflows$#', $uri)) {
                    return $workflowsResponse;
                } elseif (str_contains($uri, '/api/v1/workflows/1')) {
                    return $workflowResponse;
                }
                throw new \RuntimeException('Unexpected URI: ' . $uri);
            });

        $formatter = $this->createMock(\Ngramx\Output\OutputFormatter::class);
        $formatter->expects($this->never())
            ->method('info');

        $command = $this->createCommand();
        $skipped = $this->invokeMethod($command, 'performExport', [$env, $this->workflowsDir, true, $formatter]);

        $this->assertFalse($skipped);
        $fileContent = file_get_contents($this->workflowsDir . '/Test Workflow.json');
        $this->assertIsString($fileContent);
        $content = json_decode($fileContent, true);
        $this->assertSame('1', $content['id']);
    }

    public function test_performExport_skips_invalid_workflow_entries(): void
    {
        $env = [
            'NGRAMX_N8N_HOST' => 'http://localhost',
            'NGRAMX_N8N_PORT' => '5678',
            'NGRAMX_N8N_API_KEY' => 'test-key',
        ];

        $workflowsResponse = $this->createMockResponseFromJson([
            'data' => [
                ['id' => '1', 'name' => 'Valid Workflow'],
                ['name' => 'Missing ID'], // Invalid: missing id
                ['id' => '2'], // Invalid: missing name
                ['id' => '3', 'name' => 'Valid Workflow 2'],
            ],
        ]);

        $workflow1Response = $this->createMockResponseFromJson(['id' => '1', 'name' => 'Valid Workflow', 'nodes' => []], false);
        $workflow3Response = $this->createMockResponseFromJson(['id' => '3', 'name' => 'Valid Workflow 2', 'nodes' => []], false);

        $this->httpClient->expects($this->exactly(3))
            ->method('request')
            ->willReturnCallback(function ($method, $uri) use ($workflowsResponse, $workflow1Response, $workflow3Response) {
                if (preg_match('#/api/v1/workflows$#', $uri)) {
                    return $workflowsResponse;
                } elseif (str_contains($uri, '/api/v1/workflows/1')) {
                    return $workflow1Response;
                } elseif (str_contains($uri, '/api/v1/workflows/3')) {
                    return $workflow3Response;
                }
                throw new \RuntimeException('Unexpected URI: ' . $uri);
            });

        $formatter = $this->createMock(\Ngramx\Output\OutputFormatter::class);
        $command = $this->createCommand();
        mkdir($this->workflowsDir, 0755, true);

        $skipped = $this->invokeMethod($command, 'performExport', [$env, $this->workflowsDir, false, $formatter]);

        $this->assertFalse($skipped);
        $this->assertFileExists($this->workflowsDir . '/Valid Workflow.json');
        $this->assertFileExists($this->workflowsDir . '/Valid Workflow 2.json');
        $this->assertFileDoesNotExist($this->workflowsDir . '/Missing ID.json');
    }

    public function test_performExport_handles_empty_workflows_list(): void
    {
        $env = [
            'NGRAMX_N8N_HOST' => 'http://localhost',
            'NGRAMX_N8N_PORT' => '5678',
            'NGRAMX_N8N_API_KEY' => 'test-key',
        ];

        $workflowsResponse = $this->createMockResponseFromJson(['data' => []]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($workflowsResponse);

        $formatter = $this->createMock(\Ngramx\Output\OutputFormatter::class);
        $command = $this->createCommand();
        mkdir($this->workflowsDir, 0755, true);

        $skipped = $this->invokeMethod($command, 'performExport', [$env, $this->workflowsDir, false, $formatter]);

        $this->assertFalse($skipped);
        $this->assertEmpty(glob($this->workflowsDir . '/*.json'));
    }

    public function test_performExport_handles_http_exception(): void
    {
        $env = [
            'NGRAMX_N8N_HOST' => 'http://localhost',
            'NGRAMX_N8N_PORT' => '5678',
            'NGRAMX_N8N_API_KEY' => 'test-key',
        ];

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willThrowException(new ConnectException('Connection failed', $this->createMock(RequestInterface::class)));

        $formatter = $this->createMock(\Ngramx\Output\OutputFormatter::class);
        $command = $this->createCommand();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to export n8n workflow');

        $this->invokeMethod($command, 'performExport', [$env, $this->workflowsDir, false, $formatter]);
    }

    // ==================== execute() Integration Tests ====================

    public function test_execute_successfully_exports_workflows(): void
    {
        if (@file_put_contents($this->envPath, "NGRAMX_N8N_HOST=http://localhost\nNGRAMX_N8N_PORT=5678\nNGRAMX_N8N_API_KEY=test-key") === false) {
            $this->markTestSkipped('Cannot write .env file (sandbox restrictions)');
        }

        $config = $this->createMockConfig();
        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/ngramx.yml');
        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        $workflowsResponse = $this->createMockResponseFromJson([
            'data' => [
                ['id' => '1', 'name' => 'Workflow 1'],
            ],
        ]);

        $workflowResponse = $this->createMockResponseFromJson(['id' => '1', 'name' => 'Workflow 1', 'nodes' => []], false);

        $this->httpClient->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function ($method, $uri) use ($workflowsResponse, $workflowResponse) {
                if (preg_match('#/api/v1/workflows$#', $uri)) {
                    return $workflowsResponse;
                } elseif (str_contains($uri, '/api/v1/workflows/1')) {
                    return $workflowResponse;
                }
                throw new \RuntimeException('Unexpected URI: ' . $uri);
            });

        $cwd = getcwd();
        $originalDir = $cwd !== false ? $cwd : '/';
        chdir($this->testDir);

        try {
            $command = new ExportCommand($this->configLoader, $this->httpClient);
            $tester = $this->createCommandTester($command);

            $exitCode = $tester->execute([], ['interactive' => false]);

            $this->assertSame(0, $exitCode);
            $this->assertDirectoryExists($this->workflowsDir);
            $this->assertFileExists($this->workflowsDir . '/Workflow 1.json');
            $this->assertStringContainsString('Workflow export complete', $tester->getDisplay());
        } finally {
            chdir($originalDir);
        }
    }

    public function test_execute_creates_workflows_directory_if_missing(): void
    {
        if (@file_put_contents($this->envPath, "NGRAMX_N8N_HOST=http://localhost\nNGRAMX_N8N_PORT=5678\nNGRAMX_N8N_API_KEY=test-key") === false) {
            $this->markTestSkipped('Cannot write .env file (sandbox restrictions)');
        }

        $config = $this->createMockConfig();
        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/ngramx.yml');
        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        $this->assertDirectoryDoesNotExist($this->workflowsDir);

        $workflowsResponse = $this->createMockResponseFromJson(['data' => []]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($workflowsResponse);

        $cwd = getcwd();
        $originalDir = $cwd !== false ? $cwd : '/';
        chdir($this->testDir);

        try {
            $command = new ExportCommand($this->configLoader, $this->httpClient);
            $tester = $this->createCommandTester($command);

            $exitCode = $tester->execute([], ['interactive' => false]);

            $this->assertSame(0, $exitCode);
            $this->assertDirectoryExists($this->workflowsDir);
        } finally {
            chdir($originalDir);
        }
    }

    public function test_execute_handles_config_exception(): void
    {
        if (@file_put_contents($this->envPath, "NGRAMX_N8N_HOST=http://localhost\nNGRAMX_N8N_PORT=5678\nNGRAMX_N8N_API_KEY=test-key") === false) {
            $this->markTestSkipped('Cannot write .env file (sandbox restrictions)');
        }

        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willThrowException(new ConfigException('Config not found'));

        $cwd = getcwd();
        $originalDir = $cwd !== false ? $cwd : '/';
        chdir($this->testDir);

        try {
            $command = new ExportCommand($this->configLoader, $this->httpClient);
            $tester = $this->createCommandTester($command);

            $exitCode = $tester->execute([], ['interactive' => false]);

            $this->assertSame(1, $exitCode);
            $display = $tester->getDisplay();
            $this->assertTrue(
                str_contains($display, 'Config not found') || str_contains($display, 'NGRAMX_N8N_HOST is required'),
                'Should show error: ' . $display
            );
        } finally {
            chdir($originalDir);
        }
    }

    public function test_execute_handles_http_exception(): void
    {
        if (@file_put_contents($this->envPath, "NGRAMX_N8N_HOST=http://localhost\nNGRAMX_N8N_PORT=5678\nNGRAMX_N8N_API_KEY=test-key") === false) {
            $this->markTestSkipped('Cannot write .env file (sandbox restrictions)');
        }

        $config = $this->createMockConfig();
        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/ngramx.yml');
        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willThrowException(new RequestException('Connection failed', $this->createMock(RequestInterface::class)));

        $cwd = getcwd();
        $originalDir = $cwd !== false ? $cwd : '/';
        chdir($this->testDir);

        try {
            $command = new ExportCommand($this->configLoader, $this->httpClient);
            $tester = $this->createCommandTester($command);

            $exitCode = $tester->execute([], ['interactive' => false]);

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString('Failed to export n8n workflow', $tester->getDisplay());
        } finally {
            chdir($originalDir);
        }
    }

    // ==================== Helper Methods ====================

    private function createCommandTester(ExportCommand $command): CommandTester
    {
        $application = new Application();
        $application->add($command);
        $command = $application->find('n8n:export');
        return new CommandTester($command);
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
            workflowsDir: $this->workflowsDir
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
