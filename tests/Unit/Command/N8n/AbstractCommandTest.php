<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Command\N8n;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use Ngramx\Command\N8n\AbstractCommand;
use Ngramx\Command\N8n\ExportCommand;
use Ngramx\Config\ConfigLoader;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AbstractCommandTest extends TestCase
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

    // ==================== Helper Methods ====================

    /**
     * Create an instance of a command for testing (using N8nExportCommand as a concrete implementation)
     */
    private function createCommand(): AbstractCommand
    {
        return new ExportCommand($this->configLoader, $this->httpClient);
    }

    /**
     * Create an instance of a command with a helper set
     */
    private function createCommandWithHelperSet(): AbstractCommand
    {
        $command = new ExportCommand($this->configLoader, $this->httpClient);
        $application = new Application();
        $application->add($command);
        $foundCommand = $application->find('n8n:export');
        $this->assertInstanceOf(ExportCommand::class, $foundCommand);
        /** @var ExportCommand $foundCommand */
        return $foundCommand;
    }

    // ==================== loadEnv() Tests ====================

    public function test_loadEnv_creates_empty_env_file_when_missing(): void
    {
        if (file_exists($this->envPath)) {
            @unlink($this->envPath);
        }

        $command = $this->createCommand();
        $result = $this->invokeMethod($command, 'loadEnv', [$this->envPath]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
        if (file_exists($this->envPath)) {
            $fileContent = file_get_contents($this->envPath);
            $this->assertIsString($fileContent);
            $this->assertSame('', $fileContent);
        } else {
            $this->markTestSkipped('Cannot create .env file (sandbox restrictions)');
        }
    }

    public function test_loadEnv_loads_existing_env_file(): void
    {
        $envContent = "NGRAMX_N8N_HOST=http://localhost\nNGRAMX_N8N_PORT=5678\nNGRAMX_N8N_API_KEY=test-key-123";
        if (@file_put_contents($this->envPath, $envContent) === false) {
            $this->markTestSkipped('Cannot write .env file (sandbox restrictions)');
        }

        $command = $this->createCommand();
        $result = $this->invokeMethod($command, 'loadEnv', [$this->envPath]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('NGRAMX_N8N_HOST', $result);
        $this->assertArrayHasKey('NGRAMX_N8N_PORT', $result);
        $this->assertArrayHasKey('NGRAMX_N8N_API_KEY', $result);
        $this->assertSame('http://localhost', $result['NGRAMX_N8N_HOST']);
        $this->assertSame('5678', $result['NGRAMX_N8N_PORT']);
        $this->assertSame('test-key-123', $result['NGRAMX_N8N_API_KEY']);
    }

    // ==================== escapeEnvValue() Tests ====================

    public function test_escapeEnvValue_handles_empty_string(): void
    {
        $command = $this->createCommand();
        $result = $this->invokeMethod($command, 'escapeEnvValue', ['']);
        $this->assertSame('""', $result);
    }

    public function test_escapeEnvValue_handles_values_with_special_characters(): void
    {
        $command = $this->createCommand();
        $testCases = [
            'value with spaces' => '"value with spaces"',
            'value"with"quotes' => '"value\"with\"quotes"',
            'value\\with\\backslashes' => '"value\\\\with\\\\backslashes"',
            'value$with$dollar' => '"value$with$dollar"',
            'value`with`backtick' => '"value`with`backtick"',
            "value'with'single" => '"value\'with\'single"',
        ];

        foreach ($testCases as $input => $expected) {
            $result = $this->invokeMethod($command, 'escapeEnvValue', [$input]);
            $this->assertSame($expected, $result, "Failed for input: $input");
        }
    }

    public function test_escapeEnvValue_handles_normal_values_without_escaping(): void
    {
        $command = $this->createCommand();
        $normalValues = ['localhost', '5678', 'test-key-123', 'http://localhost'];

        foreach ($normalValues as $value) {
            $result = $this->invokeMethod($command, 'escapeEnvValue', [$value]);
            $this->assertSame($value, $result, "Failed for value: $value");
        }
    }

    // ==================== writeEnv() Tests ====================

    public function test_writeEnv_writes_sorted_env_variables(): void
    {
        $command = $this->createCommand();
        $env = [
            'NGRAMX_N8N_API_KEY' => 'test-key',
            'NGRAMX_N8N_HOST' => 'http://localhost',
            'NGRAMX_N8N_PORT' => '5678',
        ];

        $this->invokeMethod($command, 'writeEnv', [$this->envPath, $env]);

        if (file_exists($this->envPath)) {
            $content = file_get_contents($this->envPath);
            $this->assertIsString($content);
            $lines = array_filter(explode(PHP_EOL, trim($content)), fn ($line) => $line !== '');
            $this->assertStringStartsWith('NGRAMX_N8N_API_KEY', $lines[0] ?? '');
            $this->assertStringStartsWith('NGRAMX_N8N_HOST', $lines[1] ?? '');
            $this->assertStringStartsWith('NGRAMX_N8N_PORT', $lines[2] ?? '');
        } else {
            $this->markTestSkipped('Cannot write .env file (sandbox restrictions)');
        }
    }

    public function test_writeEnv_escapes_special_characters(): void
    {
        $command = $this->createCommand();
        $env = [
            'NGRAMX_N8N_API_KEY' => 'key with spaces and "quotes"',
        ];

        $this->invokeMethod($command, 'writeEnv', [$this->envPath, $env]);

        if (file_exists($this->envPath)) {
            $content = file_get_contents($this->envPath);
            $this->assertIsString($content);
            // Quotes inside a quoted string should be escaped with backslashes
            $this->assertStringContainsString('"key with spaces and \"quotes\""', $content);
        } else {
            $this->markTestSkipped('Cannot write .env file (sandbox restrictions)');
        }
    }

    // ==================== promptForMissingEnvValues() Tests ====================

    public function test_promptForMissingEnvValues_returns_env_when_all_values_present(): void
    {
        $command = $this->createCommandWithHelperSet();
        $input = $this->createMock(InputInterface::class);
        $output = $this->createMock(OutputInterface::class);

        $env = [
            'NGRAMX_N8N_HOST' => 'http://localhost',
            'NGRAMX_N8N_PORT' => '5678',
            'NGRAMX_N8N_API_KEY' => 'test-key',
        ];

        $result = $this->invokeMethod($command, 'promptForMissingEnvValues', [$env, $input, $output]);
        $this->assertSame($env, $result);
    }

    public function test_promptForMissingEnvValues_prompts_for_missing_values(): void
    {
        $command = $this->createCommandWithHelperSet();
        $input = $this->createMock(InputInterface::class);
        $output = $this->createMock(OutputInterface::class);
        $questionHelper = $this->createMock(QuestionHelper::class);
        $questionHelper->expects($this->exactly(3))
            ->method('ask')
            ->willReturnOnConsecutiveCalls('http://localhost', '5678', 'test-key');

        $helperSet = new HelperSet(['question' => $questionHelper]);
        $command->setHelperSet($helperSet);

        $result = $this->invokeMethod($command, 'promptForMissingEnvValues', [[], $input, $output]);

        $this->assertArrayHasKey('NGRAMX_N8N_HOST', $result);
        $this->assertArrayHasKey('NGRAMX_N8N_PORT', $result);
        $this->assertArrayHasKey('NGRAMX_N8N_API_KEY', $result);
    }

    public function test_promptForMissingEnvValues_throws_exception_on_null_input(): void
    {
        $command = $this->createCommandWithHelperSet();
        $input = $this->createMock(InputInterface::class);
        $output = $this->createMock(OutputInterface::class);
        $questionHelper = $this->createMock(QuestionHelper::class);
        $questionHelper->expects($this->once())
            ->method('ask')
            ->willReturn(null);

        $helperSet = new HelperSet(['question' => $questionHelper]);
        $command->setHelperSet($helperSet);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('NGRAMX_N8N_HOST is required');

        $this->invokeMethod($command, 'promptForMissingEnvValues', [[], $input, $output]);
    }

    // ==================== buildApiOptions() Tests ====================

    public function test_buildApiOptions_creates_correct_headers_without_content_type(): void
    {
        $command = $this->createCommand();
        $env = [
            'NGRAMX_N8N_HOST' => 'http://localhost',
            'NGRAMX_N8N_PORT' => '5678',
            'NGRAMX_N8N_API_KEY' => 'test-api-key',
        ];

        $result = $this->invokeMethod($command, 'buildApiOptions', [$env, false]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('headers', $result);
        $this->assertArrayHasKey('X-N8N-API-KEY', $result['headers']);
        $this->assertArrayHasKey('Accept', $result['headers']);
        $this->assertArrayNotHasKey('Content-Type', $result['headers']);
        $this->assertSame('test-api-key', $result['headers']['X-N8N-API-KEY']);
        $this->assertSame('application/json', $result['headers']['Accept']);
    }

    public function test_buildApiOptions_creates_correct_headers_with_content_type(): void
    {
        $command = $this->createCommand();
        $env = [
            'NGRAMX_N8N_HOST' => 'http://localhost',
            'NGRAMX_N8N_PORT' => '5678',
            'NGRAMX_N8N_API_KEY' => 'test-api-key',
        ];

        $result = $this->invokeMethod($command, 'buildApiOptions', [$env, true]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('headers', $result);
        $this->assertArrayHasKey('X-N8N-API-KEY', $result['headers']);
        $this->assertArrayHasKey('Accept', $result['headers']);
        $this->assertArrayHasKey('Content-Type', $result['headers']);
        $this->assertSame('test-api-key', $result['headers']['X-N8N-API-KEY']);
        $this->assertSame('application/json', $result['headers']['Accept']);
        $this->assertSame('application/json', $result['headers']['Content-Type']);
    }

    // ==================== buildBaseUri() Tests ====================

    public function test_buildBaseUri_constructs_uri_from_env(): void
    {
        $command = $this->createCommand();
        $env = [
            'NGRAMX_N8N_HOST' => 'http://localhost',
            'NGRAMX_N8N_PORT' => '5678',
            'NGRAMX_N8N_API_KEY' => 'test-key',
        ];

        $result = $this->invokeMethod($command, 'buildBaseUri', [$env]);

        $this->assertSame('http://localhost:5678', $result);
    }

    public function test_buildBaseUri_handles_host_with_trailing_slash(): void
    {
        $command = $this->createCommand();
        $env = [
            'NGRAMX_N8N_HOST' => 'http://localhost/',
            'NGRAMX_N8N_PORT' => '5678',
            'NGRAMX_N8N_API_KEY' => 'test-key',
        ];

        $result = $this->invokeMethod($command, 'buildBaseUri', [$env]);
        $this->assertSame('http://localhost/:5678', $result);
    }

    // ==================== buildWorkflowsUri() Tests ====================

    public function test_buildWorkflowsUri_appends_endpoint(): void
    {
        $command = $this->createCommand();
        $baseUri = 'http://localhost:5678';

        $result = $this->invokeMethod($command, 'buildWorkflowsUri', [$baseUri]);

        $this->assertSame('http://localhost:5678/api/v1/workflows', $result);
    }

    public function test_buildWorkflowsUri_handles_trailing_slash(): void
    {
        $command = $this->createCommand();
        $baseUri = 'http://localhost:5678/';

        $result = $this->invokeMethod($command, 'buildWorkflowsUri', [$baseUri]);

        $this->assertSame('http://localhost:5678/api/v1/workflows', $result);
    }

    // ==================== buildWorkflowUri() Tests ====================

    public function test_buildWorkflowUri_appends_workflow_id(): void
    {
        $command = $this->createCommand();
        $baseUri = 'http://localhost:5678';
        $workflowId = '123';

        $result = $this->invokeMethod($command, 'buildWorkflowUri', [$baseUri, $workflowId]);

        $this->assertSame('http://localhost:5678/api/v1/workflows/123', $result);
    }

    public function test_buildWorkflowUri_handles_trailing_slash(): void
    {
        $command = $this->createCommand();
        $baseUri = 'http://localhost:5678/';
        $workflowId = '456';

        $result = $this->invokeMethod($command, 'buildWorkflowUri', [$baseUri, $workflowId]);

        $this->assertSame('http://localhost:5678/api/v1/workflows/456', $result);
    }

    // ==================== fetchWorkflowsList() Tests ====================

    public function test_fetchWorkflowsList_returns_workflows_array(): void
    {
        $command = $this->createCommand();
        $workflowsData = [
            'data' => [
                ['id' => '1', 'name' => 'Workflow 1'],
                ['id' => '2', 'name' => 'Workflow 2'],
            ],
        ];

        $response = $this->createMockResponseFromJson($workflowsData);
        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $result = $this->invokeMethod($command, 'fetchWorkflowsList', ['http://localhost/api/v1/workflows', []]);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertSame('1', $result[0]['id']);
        $this->assertSame('2', $result[1]['id']);
    }

    public function test_fetchWorkflowsList_throws_exception_on_missing_data_key(): void
    {
        $command = $this->createCommand();
        $invalidResponse = ['workflows' => []]; // Missing 'data' key

        $response = $this->createMockResponseFromJson($invalidResponse);
        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid workflows response: missing data array');

        $this->invokeMethod($command, 'fetchWorkflowsList', ['http://localhost/api/v1/workflows', []]);
    }

    public function test_fetchWorkflowsList_throws_exception_on_non_array_data(): void
    {
        $command = $this->createCommand();
        $invalidResponse = ['data' => 'not-an-array'];

        $response = $this->createMockResponseFromJson($invalidResponse);
        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid workflows response: missing data array');

        $this->invokeMethod($command, 'fetchWorkflowsList', ['http://localhost/api/v1/workflows', []]);
    }

    public function test_fetchWorkflowsList_handles_http_exception(): void
    {
        $command = $this->createCommand();
        $this->httpClient->expects($this->once())
            ->method('request')
            ->willThrowException(new ConnectException('Connection failed', $this->createMock(RequestInterface::class)));

        $this->expectException(ConnectException::class);

        $this->invokeMethod($command, 'fetchWorkflowsList', ['http://localhost/api/v1/workflows', []]);
    }

    // ==================== getEnvPath() Tests ====================

    public function test_getEnvPath_returns_current_directory_env_path(): void
    {
        $cwd = getcwd();
        $originalDir = $cwd !== false ? $cwd : '/';
        chdir($this->testDir);

        try {
            $command = $this->createCommand();
            $result = $this->invokeMethod($command, 'getEnvPath', []);

            $this->assertStringEndsWith('/.env', $result);
            $this->assertStringContainsString($this->testDir, $result);
        } finally {
            chdir($originalDir);
        }
    }

    public function test_fetchWorkflowsList_throws_exception_when_data_missing(): void
    {
        $command = $this->createCommand();
        $response = $this->createMockResponseFromJson(['notData' => []]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid workflows response: missing data array');

        $this->invokeMethod($command, 'fetchWorkflowsList', ['http://localhost:5678/api/v1/workflows', []]);
    }

    public function test_fetchWorkflowsList_throws_exception_when_data_not_array(): void
    {
        $command = $this->createCommand();
        $response = $this->createMockResponseFromJson(['data' => 'not an array']);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid workflows response: missing data array');

        $this->invokeMethod($command, 'fetchWorkflowsList', ['http://localhost:5678/api/v1/workflows', []]);
    }

}
