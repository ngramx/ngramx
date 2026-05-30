<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Command\N8n;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Ngramx\Command\N8n\ImportCommand;
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

class ImportCommandTest extends TestCase
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
        $command = new ImportCommand($this->configLoader, $this->httpClient);

        $this->assertSame('n8n:import', $command->getName());
        $this->assertSame('Import n8n workflows from JSON files', $command->getDescription());

        $definition = $command->getDefinition();
        $this->assertTrue($definition->hasOption('force'));
        $this->assertTrue($definition->hasShortcut('f'));
        $this->assertFalse($definition->getOption('force')->isValueRequired());
    }

    // ==================== Helper Methods ====================

    private function createCommand(): ImportCommand
    {
        return new ImportCommand($this->configLoader, $this->httpClient);
    }

    // ==================== getWorkflowFiles() Tests ====================

    public function test_getWorkflowFiles_returns_json_files_from_directory(): void
    {
        mkdir($this->workflowsDir, 0755, true);
        file_put_contents($this->workflowsDir . '/workflow1.json', '{}');
        file_put_contents($this->workflowsDir . '/workflow2.json', '{}');
        file_put_contents($this->workflowsDir . '/readme.txt', 'not a json file');

        $command = $this->createCommand();
        $result = $this->invokeMethod($command, 'getWorkflowFiles', [$this->workflowsDir]);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertStringContainsString('workflow1.json', $result[0]);
        $this->assertStringContainsString('workflow2.json', $result[1]);
    }

    public function test_getWorkflowFiles_returns_empty_array_when_directory_does_not_exist(): void
    {
        $command = $this->createCommand();
        $result = $this->invokeMethod($command, 'getWorkflowFiles', [$this->workflowsDir . '/nonexistent']);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_getWorkflowFiles_returns_empty_array_when_no_json_files(): void
    {
        mkdir($this->workflowsDir, 0755, true);
        file_put_contents($this->workflowsDir . '/readme.txt', 'not a json file');

        $command = $this->createCommand();
        $result = $this->invokeMethod($command, 'getWorkflowFiles', [$this->workflowsDir]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // ==================== updateWorkflowName() Tests ====================

    public function test_updateWorkflowName_sets_name_in_workflow_data(): void
    {
        $command = $this->createCommand();
        $workflowData = ['id' => '1', 'name' => 'Old Name', 'nodes' => []];

        $result = $this->invokeMethod($command, 'updateWorkflowName', [$workflowData, 'New Name']);

        $this->assertSame('New Name', $result['name']);
        $this->assertSame('1', $result['id']);
    }

    public function test_updateWorkflowName_adds_name_when_missing(): void
    {
        $command = $this->createCommand();
        $workflowData = ['id' => '1', 'nodes' => []];

        $result = $this->invokeMethod($command, 'updateWorkflowName', [$workflowData, 'New Name']);

        $this->assertSame('New Name', $result['name']);
    }

    // ==================== findExistingWorkflowId() Tests ====================

    public function test_findExistingWorkflowId_returns_id_when_workflow_exists(): void
    {
        $command = $this->createCommand();
        $existingWorkflows = [
            ['id' => '1', 'name' => 'Workflow 1'],
            ['id' => '2', 'name' => 'Workflow 2'],
        ];

        $result = $this->invokeMethod($command, 'findExistingWorkflowId', [$existingWorkflows, 'Workflow 1']);

        $this->assertSame('1', $result);
    }

    public function test_findExistingWorkflowId_returns_null_when_workflow_not_exists(): void
    {
        $command = $this->createCommand();
        $existingWorkflows = [
            ['id' => '1', 'name' => 'Workflow 1'],
        ];

        $result = $this->invokeMethod($command, 'findExistingWorkflowId', [$existingWorkflows, 'Non-existent']);

        $this->assertNull($result);
    }

    public function test_findExistingWorkflowId_returns_null_when_id_missing(): void
    {
        $command = $this->createCommand();
        $existingWorkflows = [
            ['name' => 'Workflow Without ID'],
        ];

        $result = $this->invokeMethod($command, 'findExistingWorkflowId', [$existingWorkflows, 'Workflow Without ID']);

        $this->assertNull($result);
    }

    // ==================== performImport() Integration Tests ====================

    public function test_performImport_successfully_imports_workflows(): void
    {
        $env = [
            'NGRAMX_N8N_HOST' => 'http://localhost',
            'NGRAMX_N8N_PORT' => '5678',
            'NGRAMX_N8N_API_KEY' => 'test-key',
        ];

        mkdir($this->workflowsDir, 0755, true);
        file_put_contents($this->workflowsDir . '/Workflow1.json', json_encode(['id' => '1', 'name' => 'Old Name', 'nodes' => []]));
        file_put_contents($this->workflowsDir . '/Workflow2.json', json_encode(['id' => '2', 'name' => 'Old Name 2', 'nodes' => []]));

        // Create mock responses - these will be returned from the callback
        $workflowsResponse = $this->createMockResponseFromJson(['data' => []]);
        $postResponse = $this->createMockResponseFromJson(['id' => 'new-id'], false);

        $this->httpClient->expects($this->exactly(3))
            ->method('request')
            ->willReturnCallback(function ($method, $uri, $options) use ($workflowsResponse, $postResponse) {
                if ($method === 'GET' && preg_match('#/api/v1/workflows$#', $uri)) {
                    return $workflowsResponse;
                } elseif ($method === 'POST' && preg_match('#/api/v1/workflows$#', $uri)) {
                    // Verify the workflow name was updated
                    $workflowData = $options['json'];
                    $this->assertArrayHasKey('name', $workflowData);
                    $this->assertArrayNotHasKey('id', $workflowData); // ID should be removed for new workflows
                    return $postResponse;
                }
                throw new \RuntimeException('Unexpected request: ' . $method . ' ' . $uri);
            });

        $formatter = $this->createMock(\Ngramx\Output\OutputFormatter::class);
        $formatter->expects($this->exactly(2))
            ->method('info')
            ->with($this->stringContains('Imported workflow'));

        $command = $this->createCommand();
        $skipped = $this->invokeMethod($command, 'performImport', [$env, $this->workflowsDir, false, $formatter]);

        $this->assertFalse($skipped);
    }

    public function test_performImport_skips_existing_workflows_without_force(): void
    {
        $env = [
            'NGRAMX_N8N_HOST' => 'http://localhost',
            'NGRAMX_N8N_PORT' => '5678',
            'NGRAMX_N8N_API_KEY' => 'test-key',
        ];

        mkdir($this->workflowsDir, 0755, true);
        file_put_contents($this->workflowsDir . '/Existing Workflow.json', json_encode(['id' => '1', 'name' => 'Old Name', 'nodes' => []]));

        $workflowsResponse = $this->createMockResponseFromJson([
            'data' => [
                ['id' => '1', 'name' => 'Existing Workflow'],
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
        $skipped = $this->invokeMethod($command, 'performImport', [$env, $this->workflowsDir, false, $formatter]);

        $this->assertTrue($skipped);
    }

    public function test_performImport_updates_existing_workflows_with_force(): void
    {
        $env = [
            'NGRAMX_N8N_HOST' => 'http://localhost',
            'NGRAMX_N8N_PORT' => '5678',
            'NGRAMX_N8N_API_KEY' => 'test-key',
        ];

        mkdir($this->workflowsDir, 0755, true);
        file_put_contents($this->workflowsDir . '/Existing Workflow.json', json_encode(['id' => '1', 'name' => 'Old Name', 'nodes' => []]));

        $workflowsResponse = $this->createMockResponseFromJson([
            'data' => [
                ['id' => '1', 'name' => 'Existing Workflow'],
            ],
        ]);

        $this->httpClient->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function ($method, $uri, $options) use ($workflowsResponse) {
                if ($method === 'GET' && preg_match('#/api/v1/workflows$#', $uri)) {
                    return $workflowsResponse;
                } elseif ($method === 'PUT' && preg_match('#/api/v1/workflows/1$#', $uri)) {
                    // Verify the workflow data - ID should NOT be in the body (only in URL)
                    $workflowData = $options['json'];
                    $this->assertArrayNotHasKey('id', $workflowData); // ID should not be in body for PUT requests
                    $this->assertSame('Existing Workflow', $workflowData['name']);
                    return $this->createMockResponseFromJson(['id' => '1'], false);
                }
                throw new \RuntimeException('Unexpected request: ' . $method . ' ' . $uri);
            });

        $formatter = $this->createMock(\Ngramx\Output\OutputFormatter::class);
        $formatter->expects($this->once())
            ->method('info')
            ->with($this->stringContains('Updated workflow'));

        $command = $this->createCommand();
        $skipped = $this->invokeMethod($command, 'performImport', [$env, $this->workflowsDir, true, $formatter]);

        $this->assertFalse($skipped);
    }

    public function test_performImport_handles_invalid_json_files(): void
    {
        $env = [
            'NGRAMX_N8N_HOST' => 'http://localhost',
            'NGRAMX_N8N_PORT' => '5678',
            'NGRAMX_N8N_API_KEY' => 'test-key',
        ];

        mkdir($this->workflowsDir, 0755, true);
        file_put_contents($this->workflowsDir . '/Invalid.json', 'not valid json');

        $workflowsResponse = $this->createMockResponseFromJson(['data' => []]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($workflowsResponse);

        $formatter = $this->createMock(\Ngramx\Output\OutputFormatter::class);
        $formatter->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Invalid JSON'));

        $command = $this->createCommand();
        $skipped = $this->invokeMethod($command, 'performImport', [$env, $this->workflowsDir, false, $formatter]);

        $this->assertFalse($skipped);
    }

    public function test_performImport_handles_file_read_errors(): void
    {
        $env = [
            'NGRAMX_N8N_HOST' => 'http://localhost',
            'NGRAMX_N8N_PORT' => '5678',
            'NGRAMX_N8N_API_KEY' => 'test-key',
        ];

        mkdir($this->workflowsDir, 0755, true);
        // Create a directory with the same name as a file to cause read error
        mkdir($this->workflowsDir . '/Workflow.json', 0755, true);

        $workflowsResponse = $this->createMockResponseFromJson(['data' => []]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($workflowsResponse);

        $formatter = $this->createMock(\Ngramx\Output\OutputFormatter::class);
        $formatter->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Failed to read file'));

        $command = $this->createCommand();
        $skipped = $this->invokeMethod($command, 'performImport', [$env, $this->workflowsDir, false, $formatter]);

        $this->assertFalse($skipped);
    }

    public function test_performImport_handles_empty_workflows_directory(): void
    {
        $env = [
            'NGRAMX_N8N_HOST' => 'http://localhost',
            'NGRAMX_N8N_PORT' => '5678',
            'NGRAMX_N8N_API_KEY' => 'test-key',
        ];

        mkdir($this->workflowsDir, 0755, true);

        $workflowsResponse = $this->createMockResponseFromJson(['data' => []]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($workflowsResponse);

        $formatter = $this->createMock(\Ngramx\Output\OutputFormatter::class);
        $formatter->expects($this->once())
            ->method('info')
            ->with($this->stringContains('No workflow files found'));

        $command = $this->createCommand();
        $skipped = $this->invokeMethod($command, 'performImport', [$env, $this->workflowsDir, false, $formatter]);

        $this->assertFalse($skipped);
    }

    public function test_performImport_handles_http_exception(): void
    {
        $env = [
            'NGRAMX_N8N_HOST' => 'http://localhost',
            'NGRAMX_N8N_PORT' => '5678',
            'NGRAMX_N8N_API_KEY' => 'test-key',
        ];

        mkdir($this->workflowsDir, 0755, true);
        file_put_contents($this->workflowsDir . '/Workflow.json', json_encode(['id' => '1', 'name' => 'Test', 'nodes' => []]));

        $workflowsResponse = $this->createMockResponseFromJson(['data' => []]);

        $this->httpClient->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function ($method, $uri) use ($workflowsResponse) {
                if ($method === 'GET' && preg_match('#/api/v1/workflows$#', $uri)) {
                    return $workflowsResponse;
                } elseif ($method === 'POST' && preg_match('#/api/v1/workflows$#', $uri)) {
                    throw new RequestException('Connection failed', $this->createMock(RequestInterface::class));
                }
                throw new \RuntimeException('Unexpected request: ' . $method . ' ' . $uri);
            });

        $formatter = $this->createMock(\Ngramx\Output\OutputFormatter::class);
        $command = $this->createCommand();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to import n8n workflow');

        $this->invokeMethod($command, 'performImport', [$env, $this->workflowsDir, false, $formatter]);
    }

    public function test_performImport_continues_when_fetching_existing_workflows_fails(): void
    {
        $env = [
            'NGRAMX_N8N_HOST' => 'http://localhost',
            'NGRAMX_N8N_PORT' => '5678',
            'NGRAMX_N8N_API_KEY' => 'test-key',
        ];

        mkdir($this->workflowsDir, 0755, true);
        file_put_contents($this->workflowsDir . '/Workflow.json', json_encode(['id' => '1', 'name' => 'Test', 'nodes' => []]));

        // First call fails (fetching existing workflows), second succeeds (creating new workflow)
        $this->httpClient->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function ($method, $uri) {
                if ($method === 'GET' && preg_match('#/api/v1/workflows$#', $uri)) {
                    throw new RequestException('Connection failed', $this->createMock(RequestInterface::class));
                } elseif ($method === 'POST' && preg_match('#/api/v1/workflows$#', $uri)) {
                    return $this->createMockResponseFromJson(['id' => 'new-id'], false);
                }
                throw new \RuntimeException('Unexpected request');
            });

        $formatter = $this->createMock(\Ngramx\Output\OutputFormatter::class);
        $formatter->expects($this->once())
            ->method('info')
            ->with($this->stringContains('Imported workflow'));

        $command = $this->createCommand();
        $skipped = $this->invokeMethod($command, 'performImport', [$env, $this->workflowsDir, false, $formatter]);

        $this->assertFalse($skipped);
    }

    // ==================== execute() Integration Tests ====================

    public function test_execute_successfully_imports_workflows(): void
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

        mkdir($this->workflowsDir, 0755, true);
        file_put_contents($this->workflowsDir . '/Workflow1.json', json_encode(['id' => '1', 'name' => 'Test', 'nodes' => []]));

        $workflowsResponse = $this->createMockResponseFromJson(['data' => []]);

        $this->httpClient->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function ($method, $uri) use ($workflowsResponse) {
                if ($method === 'GET' && preg_match('#/api/v1/workflows$#', $uri)) {
                    return $workflowsResponse;
                } elseif ($method === 'POST' && preg_match('#/api/v1/workflows$#', $uri)) {
                    return $this->createMockResponseFromJson(['id' => 'new-id'], false);
                }
                throw new \RuntimeException('Unexpected request');
            });

        $cwd = getcwd();
        $originalDir = $cwd !== false ? $cwd : '/';
        chdir($this->testDir);

        try {
            $command = new ImportCommand($this->configLoader, $this->httpClient);
            $tester = $this->createCommandTester($command);

            $exitCode = $tester->execute([], ['interactive' => false]);

            $this->assertSame(0, $exitCode);
            $this->assertStringContainsString('Workflow import complete', $tester->getDisplay());
        } finally {
            chdir($originalDir);
        }
    }

    public function test_execute_handles_missing_workflows_directory(): void
    {
        if (@file_put_contents($this->envPath, "NGRAMX_N8N_HOST=http://localhost\nNGRAMX_N8N_PORT=5678\nNGRAMX_N8N_API_KEY=test-key") === false) {
            $this->markTestSkipped('Cannot write .env file (sandbox restrictions)');
        }

        // Use a non-existent directory
        $nonExistentDir = $this->testDir . '/nonexistent';
        $config = $this->createMockConfigWithDir($nonExistentDir);

        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/ngramx.yml');
        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        $cwd = getcwd();
        $originalDir = $cwd !== false ? $cwd : '/';
        chdir($this->testDir);

        try {
            $command = new ImportCommand($this->configLoader, $this->httpClient);
            $tester = $this->createCommandTester($command);

            $exitCode = $tester->execute([], ['interactive' => false]);

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString('Workflows directory does not exist', $tester->getDisplay());
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
            $command = new ImportCommand($this->configLoader, $this->httpClient);
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

        mkdir($this->workflowsDir, 0755, true);
        file_put_contents($this->workflowsDir . '/Workflow.json', json_encode(['id' => '1', 'name' => 'Test', 'nodes' => []]));

        $workflowsResponse = $this->createMockResponseFromJson(['data' => []]);

        $this->httpClient->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function ($method, $uri) use ($workflowsResponse) {
                if ($method === 'GET' && preg_match('#/api/v1/workflows$#', $uri)) {
                    return $workflowsResponse;
                } elseif ($method === 'POST' && preg_match('#/api/v1/workflows$#', $uri)) {
                    throw new RequestException('Connection failed', $this->createMock(RequestInterface::class));
                }
                throw new \RuntimeException('Unexpected request: ' . $method . ' ' . $uri);
            });

        $cwd = getcwd();
        $originalDir = $cwd !== false ? $cwd : '/';
        chdir($this->testDir);

        try {
            $command = new ImportCommand($this->configLoader, $this->httpClient);
            $tester = $this->createCommandTester($command);

            $exitCode = $tester->execute([], ['interactive' => false]);

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString('Failed to import n8n workflow', $tester->getDisplay());
        } finally {
            chdir($originalDir);
        }
    }

    // ==================== Helper Methods ====================

    private function createCommandTester(ImportCommand $command): CommandTester
    {
        $application = new Application();
        $application->add($command);
        $command = $application->find('n8n:import');
        return new CommandTester($command);
    }

    private function createMockConfig(): NgramxConfig
    {
        return $this->createMockConfigWithDir($this->workflowsDir);
    }

    private function createMockConfigWithDir(string $workflowsDir): NgramxConfig
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
            workflowsDir: $workflowsDir
        );

        return new NgramxConfig(
            version: '1.0',
            docker: $dockerConfig,
            setup: $setupConfig,
            n8n: $n8nConfig,
            commands: []
        );
    }

    // ==================== cleanNodeData() Tests ====================

    public function test_cleanNodeData_handles_missing_parameters(): void
    {
        $command = $this->createCommand();
        $node = ['name' => 'Test Node', 'type' => 'n8n-nodes-base.start'];

        $result = $this->invokeMethod($command, 'cleanNodeData', [$node]);

        $this->assertInstanceOf(\stdClass::class, $result['parameters']);
        $this->assertArrayNotHasKey('id', $result);
    }

    public function test_cleanNodeData_handles_empty_array_parameters(): void
    {
        $command = $this->createCommand();
        $node = ['name' => 'Test Node', 'parameters' => []];

        $result = $this->invokeMethod($command, 'cleanNodeData', [$node]);

        $this->assertInstanceOf(\stdClass::class, $result['parameters']);
    }

    public function test_cleanNodeData_handles_non_array_non_object_parameters(): void
    {
        $command = $this->createCommand();
        $node = ['name' => 'Test Node', 'parameters' => 'invalid'];

        $result = $this->invokeMethod($command, 'cleanNodeData', [$node]);

        $this->assertInstanceOf(\stdClass::class, $result['parameters']);
    }

    public function test_cleanNodeData_removes_read_only_fields(): void
    {
        $command = $this->createCommand();
        $node = [
            'name' => 'Test Node',
            'id' => 'node-123',
            'webhookId' => 'webhook-456',
            'continueOnFail' => true,
            'parameters' => ['key' => 'value'],
        ];

        $result = $this->invokeMethod($command, 'cleanNodeData', [$node]);

        $this->assertArrayNotHasKey('id', $result);
        $this->assertArrayNotHasKey('webhookId', $result);
        $this->assertArrayNotHasKey('continueOnFail', $result);
        $this->assertArrayHasKey('parameters', $result);
    }

    // ==================== prepareWorkflowForApi() Tests ====================

    public function test_prepareWorkflowForApi_handles_empty_connections_array(): void
    {
        $command = $this->createCommand();
        $workflowData = [
            'name' => 'Test',
            'connections' => [],
            'nodes' => [],
        ];

        $result = $this->invokeMethod($command, 'prepareWorkflowForApi', [$workflowData, false]);

        $this->assertInstanceOf(\stdClass::class, $result['connections']);
    }

    public function test_prepareWorkflowForApi_handles_connections_object(): void
    {
        $command = $this->createCommand();
        $connections = new \stdClass();
        $connections->node1 = ['main' => []];
        $workflowData = [
            'name' => 'Test',
            'connections' => $connections,
            'nodes' => [],
        ];

        $result = $this->invokeMethod($command, 'prepareWorkflowForApi', [$workflowData, false]);

        $this->assertIsObject($result['connections']);
    }

    public function test_prepareWorkflowForApi_handles_connections_fallback(): void
    {
        $command = $this->createCommand();
        $workflowData = [
            'name' => 'Test',
            'connections' => 'invalid',
            'nodes' => [],
        ];

        $result = $this->invokeMethod($command, 'prepareWorkflowForApi', [$workflowData, false]);

        $this->assertInstanceOf(\stdClass::class, $result['connections']);
    }

    public function test_prepareWorkflowForApi_handles_settings_with_allowed_keys(): void
    {
        $command = $this->createCommand();
        $workflowData = [
            'name' => 'Test',
            'settings' => [
                'executionOrder' => 'v1',
                'saveDataErrorExecution' => true,
                'timezone' => 'UTC',
                'invalidKey' => 'should be removed',
            ],
            'nodes' => [],
        ];

        $result = $this->invokeMethod($command, 'prepareWorkflowForApi', [$workflowData, false]);

        $this->assertArrayHasKey('settings', $result);
        $this->assertArrayHasKey('executionOrder', $result['settings']);
        $this->assertArrayHasKey('saveDataErrorExecution', $result['settings']);
        $this->assertArrayHasKey('timezone', $result['settings']);
        $this->assertArrayNotHasKey('invalidKey', $result['settings']);
    }

    public function test_prepareWorkflowForApi_handles_empty_settings(): void
    {
        $command = $this->createCommand();
        $workflowData = [
            'name' => 'Test',
            'settings' => [],
            'nodes' => [],
        ];

        $result = $this->invokeMethod($command, 'prepareWorkflowForApi', [$workflowData, false]);

        $this->assertArrayNotHasKey('settings', $result);
    }

    public function test_prepareWorkflowForApi_handles_staticData(): void
    {
        $command = $this->createCommand();
        $workflowData = [
            'name' => 'Test',
            'staticData' => ['key' => 'value'],
            'nodes' => [],
        ];

        $result = $this->invokeMethod($command, 'prepareWorkflowForApi', [$workflowData, false]);

        $this->assertArrayHasKey('staticData', $result);
        $this->assertSame(['key' => 'value'], $result['staticData']);
    }

    public function test_prepareWorkflowForApi_handles_non_array_nodes(): void
    {
        $command = $this->createCommand();
        $workflowData = [
            'name' => 'Test',
            'nodes' => ['not an array node', null],
        ];

        $result = $this->invokeMethod($command, 'prepareWorkflowForApi', [$workflowData, false]);

        $this->assertIsArray($result['nodes']);
        $this->assertEmpty($result['nodes']); // Non-array nodes are skipped
    }

    // ==================== cleanWorkflowData() Tests ====================

    public function test_cleanWorkflowData_removes_read_only_fields(): void
    {
        $command = $this->createCommand();
        $workflowData = [
            'id' => '1',
            'name' => 'Test',
            'createdAt' => '2024-01-01',
            'updatedAt' => '2024-01-02',
            'versionId' => 'v1',
            'pinData' => [],
            'meta' => [],
            'tags' => [],
            'isArchived' => false,
            'nodes' => [],
        ];

        $result = $this->invokeMethod($command, 'cleanWorkflowData', [$workflowData]);

        $this->assertArrayNotHasKey('id', $result);
        $this->assertArrayNotHasKey('createdAt', $result);
        $this->assertArrayNotHasKey('updatedAt', $result);
        $this->assertArrayNotHasKey('versionId', $result);
        $this->assertArrayNotHasKey('pinData', $result);
        $this->assertArrayNotHasKey('meta', $result);
        $this->assertArrayNotHasKey('tags', $result);
        $this->assertArrayNotHasKey('isArchived', $result);
    }

    public function test_cleanWorkflowData_filters_settings(): void
    {
        $command = $this->createCommand();
        $workflowData = [
            'name' => 'Test',
            'settings' => [
                'executionOrder' => 'v1',
                'saveManualExecutions' => true,
                'invalidSetting' => 'should be removed',
            ],
            'nodes' => [],
        ];

        $result = $this->invokeMethod($command, 'cleanWorkflowData', [$workflowData]);

        $this->assertArrayHasKey('settings', $result);
        $this->assertArrayHasKey('executionOrder', $result['settings']);
        $this->assertArrayHasKey('saveManualExecutions', $result['settings']);
        $this->assertArrayNotHasKey('invalidSetting', $result['settings']);
    }

    public function test_cleanWorkflowData_removes_empty_settings(): void
    {
        $command = $this->createCommand();
        $workflowData = [
            'name' => 'Test',
            'settings' => [
                'invalidSetting' => 'should be removed',
            ],
            'nodes' => [],
        ];

        $result = $this->invokeMethod($command, 'cleanWorkflowData', [$workflowData]);

        $this->assertArrayNotHasKey('settings', $result);
    }

    // ==================== Additional Edge Cases ====================

    public function test_performImport_handles_workflow_with_non_array_data(): void
    {
        $env = [
            'NGRAMX_N8N_HOST' => 'http://localhost',
            'NGRAMX_N8N_PORT' => '5678',
            'NGRAMX_N8N_API_KEY' => 'test-key',
        ];

        mkdir($this->workflowsDir, 0755, true);
        // Create a JSON file that decodes to a non-array (e.g., a string or number)
        file_put_contents($this->workflowsDir . '/Invalid.json', json_encode('not an array'));

        $workflowsResponse = $this->createMockResponseFromJson(['data' => []]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($workflowsResponse);

        $formatter = $this->createMock(\Ngramx\Output\OutputFormatter::class);
        $formatter->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Invalid JSON'));

        $command = $this->createCommand();
        $skipped = $this->invokeMethod($command, 'performImport', [$env, $this->workflowsDir, false, $formatter]);

        $this->assertFalse($skipped);
    }
}
