<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Command\N8n;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use Ngramx\Command\N8n\NormaliseCommand;
use Ngramx\Config\ConfigLoader;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Tester\CommandTester;

class NormaliseCommandTest extends TestCase
{
    use CommandTestTrait;

    protected ConfigLoader $configLoader;
    protected Client $httpClient;
    protected string $testDir;
    protected string $envPath;
    protected string $workflowPath;
    protected string $mapPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configLoader = $this->createMock(ConfigLoader::class);
        $this->httpClient = $this->createMock(Client::class);
        $this->testDir = sys_get_temp_dir() . '/ngramx_n8n_normalise_test_' . uniqid();
        mkdir($this->testDir, 0755, true);
        $this->envPath = $this->testDir . '/.env';
        $this->workflowPath = $this->testDir . '/workflow.json';
        $this->mapPath = $this->testDir . '/credentials.map.json';
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (is_dir($this->testDir)) {
            $this->recursiveRemoveDirectory($this->testDir);
        }
    }

    // ==================== Helper Methods ====================

    private function createCommand(): NormaliseCommand
    {
        return new NormaliseCommand($this->configLoader, $this->httpClient);
    }

    private function createCommandWithHelperSet(): NormaliseCommand
    {
        $application = new Application();
        $command = $this->createCommand();
        $application->add($command);
        $application->setAutoExit(false);

        $helperSet = new HelperSet([
            'question' => new QuestionHelper(),
        ]);
        $command->setHelperSet($helperSet);

        $foundCommand = $application->find('n8n:normalise');
        $this->assertInstanceOf(NormaliseCommand::class, $foundCommand);
        /** @var NormaliseCommand $foundCommand */
        return $foundCommand;
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     */
    private function createWorkflowJson(array $nodes = []): string
    {
        return json_encode([
            'id' => '1',
            'name' => 'Test Workflow',
            'nodes' => $nodes,
            'connections' => [],
        ], JSON_THROW_ON_ERROR);
    }

    private function createWorkflowWithCredentials(): string
    {
        return $this->createWorkflowJson([
            [
                'id' => 'node1',
                'name' => 'Read Customers',
                'type' => 'n8n-nodes-base.postgres',
                'credentials' => [
                    'postgres' => [
                        'id' => 'old-id-1',
                        'name' => 'prod-db',
                    ],
                ],
            ],
            [
                'id' => 'node2',
                'name' => 'Upsert Customer',
                'type' => 'n8n-nodes-base.postgres',
                'credentials' => [
                    'postgres' => [
                        'id' => 'old-id-1',
                        'name' => 'prod-db',
                    ],
                ],
            ],
            [
                'id' => 'node3',
                'name' => 'Notify Ops',
                'type' => 'n8n-nodes-base.slack',
                'credentials' => [
                    'slackApi' => [
                        'id' => 'old-id-2',
                        'name' => 'slack-notifications',
                    ],
                ],
            ],
        ]);
    }

    // ==================== extractCredentials() Tests ====================

    public function test_extractCredentials_extracts_credentials_from_nodes(): void
    {
        $command = $this->createCommand();
        $workflowData = json_decode($this->createWorkflowWithCredentials(), true, flags: JSON_THROW_ON_ERROR);

        $result = $this->invokeMethod($command, 'extractCredentials', [$workflowData]);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertArrayHasKey('postgres:prod-db', $result);
        $this->assertArrayHasKey('slackApi:slack-notifications', $result);
        $this->assertSame('postgres', $result['postgres:prod-db']['type']);
        $this->assertSame('prod-db', $result['postgres:prod-db']['name']);
        $this->assertContains('Read Customers', $result['postgres:prod-db']['nodes']);
        $this->assertContains('Upsert Customer', $result['postgres:prod-db']['nodes']);
    }

    public function test_extractCredentials_handles_workflow_without_nodes(): void
    {
        $command = $this->createCommand();
        $workflowData = ['id' => '1', 'name' => 'Test'];

        $result = $this->invokeMethod($command, 'extractCredentials', [$workflowData]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_extractCredentials_handles_nodes_without_credentials(): void
    {
        $command = $this->createCommand();
        $workflowData = json_decode($this->createWorkflowJson([
            ['id' => 'node1', 'name' => 'Test Node', 'type' => 'n8n-nodes-base.httpRequest'],
        ]), true, flags: JSON_THROW_ON_ERROR);

        $result = $this->invokeMethod($command, 'extractCredentials', [$workflowData]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_extractCredentials_handles_credential_without_name(): void
    {
        $command = $this->createCommand();
        $workflowData = json_decode($this->createWorkflowJson([
            [
                'id' => 'node1',
                'name' => 'Test Node',
                'credentials' => [
                    'postgres' => ['id' => '123'], // Missing 'name'
                ],
            ],
        ]), true, flags: JSON_THROW_ON_ERROR);

        $result = $this->invokeMethod($command, 'extractCredentials', [$workflowData]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_extractCredentials_handles_unnamed_node(): void
    {
        $command = $this->createCommand();
        $workflowData = json_decode($this->createWorkflowJson([
            [
                'id' => 'node1',
                // Missing 'name'
                'credentials' => [
                    'postgres' => ['id' => '123', 'name' => 'db'],
                ],
            ],
        ]), true, flags: JSON_THROW_ON_ERROR);

        $result = $this->invokeMethod($command, 'extractCredentials', [$workflowData]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('postgres:db', $result);
        $this->assertContains('unnamed', $result['postgres:db']['nodes']);
    }

    // ==================== buildCredentialsUri() Tests ====================

    public function test_buildCredentialsUri_returns_correct_uri(): void
    {
        $command = $this->createCommand();

        $result = $this->invokeMethod($command, 'buildCredentialsUri', ['http://localhost:5678']);

        $this->assertSame('http://localhost:5678/api/v1/credentials', $result);
    }

    public function test_buildCredentialsUri_handles_trailing_slash(): void
    {
        $command = $this->createCommand();

        $result = $this->invokeMethod($command, 'buildCredentialsUri', ['http://localhost:5678/']);

        $this->assertSame('http://localhost:5678/api/v1/credentials', $result);
    }

    // ==================== fetchTargetCredentials() Tests ====================

    public function test_fetchTargetCredentials_returns_credentials_map(): void
    {
        $command = $this->createCommand();
        $env = [
            'NGRAMX_N8N_HOST' => 'http://localhost',
            'NGRAMX_N8N_PORT' => '5678',
            'NGRAMX_N8N_API_KEY' => 'test-key',
        ];

        $response = $this->createMockResponseFromJson([
            'data' => [
                ['id' => '12', 'type' => 'postgres', 'name' => 'prod-db'],
                ['id' => '44', 'type' => 'slackApi', 'name' => 'slack-notifications'],
            ],
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'http://localhost:5678/api/v1/credentials', $this->anything())
            ->willReturn($response);

        $result = $this->invokeMethod($command, 'fetchTargetCredentials', [$env]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('postgres:prod-db', $result);
        $this->assertArrayHasKey('slackApi:slack-notifications', $result);
        $this->assertCount(1, $result['postgres:prod-db']);
        $this->assertSame('12', $result['postgres:prod-db'][0]['id']);
    }

    public function test_fetchTargetCredentials_handles_duplicate_credentials(): void
    {
        $command = $this->createCommand();
        $env = [
            'NGRAMX_N8N_HOST' => 'http://localhost',
            'NGRAMX_N8N_PORT' => '5678',
            'NGRAMX_N8N_API_KEY' => 'test-key',
        ];

        $response = $this->createMockResponseFromJson([
            'data' => [
                ['id' => '12', 'type' => 'postgres', 'name' => 'prod-db'],
                ['id' => '13', 'type' => 'postgres', 'name' => 'prod-db'], // Duplicate
            ],
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $result = $this->invokeMethod($command, 'fetchTargetCredentials', [$env]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('postgres:prod-db', $result);
        $this->assertCount(2, $result['postgres:prod-db']);
    }

    public function test_fetchTargetCredentials_handles_invalid_response(): void
    {
        $command = $this->createCommand();
        $env = [
            'NGRAMX_N8N_HOST' => 'http://localhost',
            'NGRAMX_N8N_PORT' => '5678',
            'NGRAMX_N8N_API_KEY' => 'test-key',
        ];

        $response = $this->createMockResponseFromJson(['invalid' => 'data']);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid credentials response: missing data array');

        $this->invokeMethod($command, 'fetchTargetCredentials', [$env]);
    }

    public function test_fetchTargetCredentials_handles_http_exception(): void
    {
        $command = $this->createCommand();
        $env = [
            'NGRAMX_N8N_HOST' => 'http://localhost',
            'NGRAMX_N8N_PORT' => '5678',
            'NGRAMX_N8N_API_KEY' => 'test-key',
        ];

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willThrowException(new ConnectException('Connection failed', $this->createMock(RequestInterface::class)));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to fetch credentials from target n8n');

        $this->invokeMethod($command, 'fetchTargetCredentials', [$env]);
    }

    // ==================== loadCredentialMap() Tests ====================

    public function test_loadCredentialMap_loads_valid_map_file(): void
    {
        $command = $this->createCommand();
        $mapContent = json_encode([
            'postgres:prod-db' => 'postgres:prod-db-v2',
            'stripeApi:billing' => 'stripeApi:stripe-prod',
        ], JSON_THROW_ON_ERROR);
        if (@file_put_contents($this->mapPath, $mapContent) === false) {
            $this->markTestSkipped('Cannot write map file (sandbox restrictions)');
        }

        $result = $this->invokeMethod($command, 'loadCredentialMap', [$this->mapPath]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('postgres:prod-db', $result);
        $this->assertSame('postgres:prod-db-v2', $result['postgres:prod-db']);
        $this->assertSame('stripeApi:stripe-prod', $result['stripeApi:billing']);
    }

    public function test_loadCredentialMap_handles_file_not_found(): void
    {
        $command = $this->createCommand();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Credential map file not found');

        $this->invokeMethod($command, 'loadCredentialMap', ['/nonexistent/file.json']);
    }

    public function test_loadCredentialMap_handles_invalid_json(): void
    {
        $command = $this->createCommand();
        if (@file_put_contents($this->mapPath, 'invalid json') === false) {
            $this->markTestSkipped('Cannot write map file (sandbox restrictions)');
        }

        $this->expectException(\JsonException::class);

        $this->invokeMethod($command, 'loadCredentialMap', [$this->mapPath]);
    }

    public function test_loadCredentialMap_handles_non_object_json(): void
    {
        $command = $this->createCommand();
        if (@file_put_contents($this->mapPath, '["array", "not", "object"]') === false) {
            $this->markTestSkipped('Cannot write map file (sandbox restrictions)');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid credential map: expected object');

        $this->invokeMethod($command, 'loadCredentialMap', [$this->mapPath]);
    }

    public function test_loadCredentialMap_filters_non_string_entries(): void
    {
        $command = $this->createCommand();
        $mapContent = json_encode([
            'postgres:prod-db' => 'postgres:prod-db-v2',
            'invalid' => 123, // Non-string value
            'also-invalid' => ['array'], // Non-string value
        ], JSON_THROW_ON_ERROR);
        if (@file_put_contents($this->mapPath, $mapContent) === false) {
            $this->markTestSkipped('Cannot write map file (sandbox restrictions)');
        }

        $result = $this->invokeMethod($command, 'loadCredentialMap', [$this->mapPath]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('postgres:prod-db', $result);
        $this->assertCount(1, $result); // Only valid entry
    }

    // ==================== validateCredentials() Tests ====================

    public function test_validateCredentials_identifies_missing_credentials(): void
    {
        $command = $this->createCommand();

        // Set required credentials
        $this->invokeMethod($command, 'extractCredentials', [json_decode($this->createWorkflowWithCredentials(), true, flags: JSON_THROW_ON_ERROR)]);
        $reflection = new \ReflectionClass($command);
        $requiredProp = $reflection->getProperty('requiredCredentials');
        $requiredProp->setAccessible(true);
        $requiredProp->setValue($command, [
            'postgres:prod-db' => ['type' => 'postgres', 'name' => 'prod-db', 'nodes' => ['Node1']],
            'stripeApi:billing' => ['type' => 'stripeApi', 'name' => 'billing', 'nodes' => ['Node2']],
        ]);

        // Set target credentials (missing stripeApi:billing)
        $targetProp = $reflection->getProperty('targetCredentials');
        $targetProp->setAccessible(true);
        $targetProp->setValue($command, [
            'postgres:prod-db' => [['id' => '12', 'type' => 'postgres', 'name' => 'prod-db']],
        ]);

        $result = $this->invokeMethod($command, 'validateCredentials', []);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('missing', $result);
        $this->assertArrayHasKey('duplicates', $result);
        $this->assertContains('stripeApi:billing', $result['missing']);
        $this->assertNotContains('postgres:prod-db', $result['missing']);
        $this->assertEmpty($result['duplicates']);
    }

    public function test_validateCredentials_identifies_duplicate_credentials(): void
    {
        $command = $this->createCommand();

        $reflection = new \ReflectionClass($command);
        $requiredProp = $reflection->getProperty('requiredCredentials');
        $requiredProp->setAccessible(true);
        $requiredProp->setValue($command, [
            'postgres:prod-db' => ['type' => 'postgres', 'name' => 'prod-db', 'nodes' => ['Node1']],
        ]);

        $targetProp = $reflection->getProperty('targetCredentials');
        $targetProp->setAccessible(true);
        $targetProp->setValue($command, [
            'postgres:prod-db' => [
                ['id' => '12', 'type' => 'postgres', 'name' => 'prod-db'],
                ['id' => '13', 'type' => 'postgres', 'name' => 'prod-db'],
            ],
        ]);

        $result = $this->invokeMethod($command, 'validateCredentials', []);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('duplicates', $result);
        $this->assertArrayHasKey('postgres:prod-db', $result['duplicates']);
        $this->assertCount(2, $result['duplicates']['postgres:prod-db']);
    }

    public function test_validateCredentials_uses_credential_map(): void
    {
        $command = $this->createCommand();

        $reflection = new \ReflectionClass($command);
        $requiredProp = $reflection->getProperty('requiredCredentials');
        $requiredProp->setAccessible(true);
        $requiredProp->setValue($command, [
            'postgres:prod-db' => ['type' => 'postgres', 'name' => 'prod-db', 'nodes' => ['Node1']],
        ]);

        $mapProp = $reflection->getProperty('credentialMap');
        $mapProp->setAccessible(true);
        $mapProp->setValue($command, [
            'postgres:prod-db' => 'postgres:prod-db-v2',
        ]);

        $targetProp = $reflection->getProperty('targetCredentials');
        $targetProp->setAccessible(true);
        $targetProp->setValue($command, [
            'postgres:prod-db-v2' => [['id' => '12', 'type' => 'postgres', 'name' => 'prod-db-v2']],
        ]);

        $result = $this->invokeMethod($command, 'validateCredentials', []);

        $this->assertIsArray($result);
        $this->assertEmpty($result['missing']);
        $this->assertEmpty($result['duplicates']);
    }

    // ==================== patchCredentialIds() Tests ====================

    public function test_patchCredentialIds_updates_credential_ids(): void
    {
        $command = $this->createCommand();
        $workflowData = json_decode($this->createWorkflowWithCredentials(), true, flags: JSON_THROW_ON_ERROR);

        $reflection = new \ReflectionClass($command);
        $targetProp = $reflection->getProperty('targetCredentials');
        $targetProp->setAccessible(true);
        $targetProp->setValue($command, [
            'postgres:prod-db' => [['id' => 'new-id-12', 'type' => 'postgres', 'name' => 'prod-db']],
            'slackApi:slack-notifications' => [['id' => 'new-id-44', 'type' => 'slackApi', 'name' => 'slack-notifications']],
        ]);

        $result = $this->invokeMethod($command, 'patchCredentialIds', [$workflowData]);

        $this->assertIsArray($result);
        $this->assertSame('new-id-12', $result['nodes'][0]['credentials']['postgres']['id']);
        $this->assertSame('new-id-12', $result['nodes'][1]['credentials']['postgres']['id']);
        $this->assertSame('new-id-44', $result['nodes'][2]['credentials']['slackApi']['id']);
        // Names should be preserved
        $this->assertSame('prod-db', $result['nodes'][0]['credentials']['postgres']['name']);
        $this->assertSame('slack-notifications', $result['nodes'][2]['credentials']['slackApi']['name']);
    }

    public function test_patchCredentialIds_uses_credential_map(): void
    {
        $command = $this->createCommand();
        $workflowData = json_decode($this->createWorkflowWithCredentials(), true, flags: JSON_THROW_ON_ERROR);

        $reflection = new \ReflectionClass($command);
        $mapProp = $reflection->getProperty('credentialMap');
        $mapProp->setAccessible(true);
        $mapProp->setValue($command, [
            'postgres:prod-db' => 'postgres:prod-db-v2',
        ]);

        $targetProp = $reflection->getProperty('targetCredentials');
        $targetProp->setAccessible(true);
        $targetProp->setValue($command, [
            'postgres:prod-db-v2' => [['id' => 'mapped-id', 'type' => 'postgres', 'name' => 'prod-db-v2']],
        ]);

        $result = $this->invokeMethod($command, 'patchCredentialIds', [$workflowData]);

        $this->assertSame('mapped-id', $result['nodes'][0]['credentials']['postgres']['id']);
    }

    public function test_patchCredentialIds_handles_missing_credentials(): void
    {
        $command = $this->createCommand();
        $workflowData = json_decode($this->createWorkflowWithCredentials(), true, flags: JSON_THROW_ON_ERROR);

        $reflection = new \ReflectionClass($command);
        $targetProp = $reflection->getProperty('targetCredentials');
        $targetProp->setAccessible(true);
        $targetProp->setValue($command, []); // No target credentials

        $result = $this->invokeMethod($command, 'patchCredentialIds', [$workflowData]);

        // Should not crash, but IDs remain unchanged
        $this->assertIsArray($result);
        $this->assertSame('old-id-1', $result['nodes'][0]['credentials']['postgres']['id']);
    }

    public function test_patchCredentialIds_handles_workflow_without_nodes(): void
    {
        $command = $this->createCommand();
        $workflowData = ['id' => '1', 'name' => 'Test'];

        $result = $this->invokeMethod($command, 'patchCredentialIds', [$workflowData]);

        $this->assertSame($workflowData, $result);
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
        $this->assertStringContainsString("\n", $result);
    }

    // ==================== generateTextReport() Tests ====================

    public function test_generateTextReport_outputs_correct_format(): void
    {
        $command = $this->createCommand();
        $env = [
            'NGRAMX_N8N_HOST' => 'http://localhost',
            'NGRAMX_N8N_PORT' => '5678',
            'NGRAMX_N8N_API_KEY' => 'test-key',
        ];

        $reflection = new \ReflectionClass($command);
        $requiredProp = $reflection->getProperty('requiredCredentials');
        $requiredProp->setAccessible(true);
        $requiredProp->setValue($command, [
            'postgres:prod-db' => ['type' => 'postgres', 'name' => 'prod-db', 'nodes' => ['Read Customers']],
        ]);

        $targetProp = $reflection->getProperty('targetCredentials');
        $targetProp->setAccessible(true);
        $targetProp->setValue($command, [
            'postgres:prod-db' => [['id' => '12', 'type' => 'postgres', 'name' => 'prod-db']],
        ]);

        $validation = ['missing' => [], 'duplicates' => []];
        $formatter = $this->createMock(\Ngramx\Output\OutputFormatter::class);
        $formatter->expects($this->atLeastOnce())->method('info');
        $formatter->expects($this->atLeastOnce())->method('success');

        $this->invokeMethod($command, 'generateTextReport', [$validation, $formatter, $env]);
    }

    // ==================== generateJsonReport() Tests ====================

    public function test_generateJsonReport_returns_correct_structure(): void
    {
        $command = $this->createCommand();

        $reflection = new \ReflectionClass($command);
        $requiredProp = $reflection->getProperty('requiredCredentials');
        $requiredProp->setAccessible(true);
        $requiredProp->setValue($command, [
            'postgres:prod-db' => ['type' => 'postgres', 'name' => 'prod-db', 'nodes' => ['Node1']],
            'stripeApi:billing' => ['type' => 'stripeApi', 'name' => 'billing', 'nodes' => ['Node2']],
        ]);

        $targetProp = $reflection->getProperty('targetCredentials');
        $targetProp->setAccessible(true);
        $targetProp->setValue($command, [
            'postgres:prod-db' => [['id' => '12', 'type' => 'postgres', 'name' => 'prod-db']],
        ]);

        $validation = [
            'missing' => ['stripeApi:billing'],
            'duplicates' => [],
        ];

        $result = $this->invokeMethod($command, 'generateJsonReport', [$validation]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('credentials', $result);
        $this->assertSame(2, $result['summary']['total']);
        $this->assertSame(1, $result['summary']['ok']);
        $this->assertSame(1, $result['summary']['missing']);
        $this->assertCount(2, $result['credentials']);
    }

    // ==================== execute() Integration Tests ====================

    public function test_execute_successfully_normalises_workflow(): void
    {
        if (@file_put_contents($this->envPath, "NGRAMX_N8N_HOST=http://localhost\nNGRAMX_N8N_PORT=5678\nNGRAMX_N8N_API_KEY=test-key\n") === false) {
            $this->markTestSkipped('Cannot write .env file (sandbox restrictions)');
        }

        file_put_contents($this->workflowPath, $this->createWorkflowWithCredentials());

        $credentialsResponse = $this->createMockResponseFromJson([
            'data' => [
                ['id' => '12', 'type' => 'postgres', 'name' => 'prod-db'],
                ['id' => '44', 'type' => 'slackApi', 'name' => 'slack-notifications'],
            ],
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($credentialsResponse);

        $cwd = getcwd();
        $originalDir = $cwd !== false ? $cwd : '/';
        chdir($this->testDir);

        try {
            $command = $this->createCommandWithHelperSet();
            $tester = new CommandTester($command);
            $outputPath = $this->testDir . '/patched.json';

            $exitCode = $tester->execute([
                'workflow' => $this->workflowPath,
                '--output' => $outputPath,
            ], ['interactive' => false]);

            $this->assertSame(0, $exitCode);
            $this->assertFileExists($outputPath);
            $patchedContent = file_get_contents($outputPath);
            $this->assertIsString($patchedContent);
            $patchedData = json_decode($patchedContent, true);
            $this->assertIsArray($patchedData);
            $this->assertSame('12', $patchedData['nodes'][0]['credentials']['postgres']['id']);
        } finally {
            chdir($originalDir);
        }
    }

    public function test_execute_outputs_to_stdout_when_no_output_option(): void
    {
        if (@file_put_contents($this->envPath, "NGRAMX_N8N_HOST=http://localhost\nNGRAMX_N8N_PORT=5678\nNGRAMX_N8N_API_KEY=test-key\n") === false) {
            $this->markTestSkipped('Cannot write .env file (sandbox restrictions)');
        }

        file_put_contents($this->workflowPath, $this->createWorkflowWithCredentials());

        $credentialsResponse = $this->createMockResponseFromJson([
            'data' => [
                ['id' => '12', 'type' => 'postgres', 'name' => 'prod-db'],
                ['id' => '44', 'type' => 'slackApi', 'name' => 'slack-notifications'],
            ],
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($credentialsResponse);

        $cwd = getcwd();
        $originalDir = $cwd !== false ? $cwd : '/';
        chdir($this->testDir);

        try {
            $command = $this->createCommandWithHelperSet();
            $tester = new CommandTester($command);

            $exitCode = $tester->execute([
                'workflow' => $this->workflowPath,
            ], ['interactive' => false]);

            $this->assertSame(0, $exitCode);
            $output = $tester->getDisplay();
            // Should contain JSON (report suppressed)
            $this->assertStringContainsString('"id"', $output);
            $this->assertStringContainsString('"name"', $output);
        } finally {
            chdir($originalDir);
        }
    }

    public function test_execute_handles_missing_credentials_in_strict_mode(): void
    {
        if (@file_put_contents($this->envPath, "NGRAMX_N8N_HOST=http://localhost\nNGRAMX_N8N_PORT=5678\nNGRAMX_N8N_API_KEY=test-key\n") === false) {
            $this->markTestSkipped('Cannot write .env file (sandbox restrictions)');
        }

        file_put_contents($this->workflowPath, $this->createWorkflowWithCredentials());

        $credentialsResponse = $this->createMockResponseFromJson([
            'data' => [
                // Missing postgres:prod-db
                ['id' => '44', 'type' => 'slackApi', 'name' => 'slack-notifications'],
            ],
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($credentialsResponse);

        $cwd = getcwd();
        $originalDir = $cwd !== false ? $cwd : '/';
        chdir($this->testDir);

        try {
            $command = $this->createCommandWithHelperSet();
            $tester = new CommandTester($command);

            $exitCode = $tester->execute([
                'workflow' => $this->workflowPath,
            ], ['interactive' => false]);

            $this->assertSame(1, $exitCode); // Should fail in strict mode
            $output = $tester->getDisplay();
            $this->assertStringContainsString('MISS', $output);
        } finally {
            chdir($originalDir);
        }
    }

    public function test_execute_handles_missing_credentials_in_no_strict_mode(): void
    {
        if (@file_put_contents($this->envPath, "NGRAMX_N8N_HOST=http://localhost\nNGRAMX_N8N_PORT=5678\nNGRAMX_N8N_API_KEY=test-key\n") === false) {
            $this->markTestSkipped('Cannot write .env file (sandbox restrictions)');
        }

        file_put_contents($this->workflowPath, $this->createWorkflowWithCredentials());

        $credentialsResponse = $this->createMockResponseFromJson([
            'data' => [
                ['id' => '44', 'type' => 'slackApi', 'name' => 'slack-notifications'],
            ],
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($credentialsResponse);

        $cwd = getcwd();
        $originalDir = $cwd !== false ? $cwd : '/';
        chdir($this->testDir);

        try {
            $command = $this->createCommandWithHelperSet();
            $tester = new CommandTester($command);

            $exitCode = $tester->execute([
                'workflow' => $this->workflowPath,
                '--no-strict' => true,
            ], ['interactive' => false]);

            $this->assertSame(0, $exitCode); // Should succeed in no-strict mode
        } finally {
            chdir($originalDir);
        }
    }

    public function test_execute_handles_workflow_file_not_found(): void
    {
        $command = $this->createCommandWithHelperSet();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'workflow' => '/nonexistent/workflow.json',
        ]);

        $this->assertSame(1, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Workflow file not found', $output);
    }

    public function test_execute_uses_credential_map_when_provided(): void
    {
        if (@file_put_contents($this->envPath, "NGRAMX_N8N_HOST=http://localhost\nNGRAMX_N8N_PORT=5678\nNGRAMX_N8N_API_KEY=test-key\n") === false) {
            $this->markTestSkipped('Cannot write .env file (sandbox restrictions)');
        }

        file_put_contents($this->workflowPath, $this->createWorkflowWithCredentials());
        file_put_contents($this->mapPath, json_encode([
            'postgres:prod-db' => 'postgres:prod-db-v2',
        ], JSON_THROW_ON_ERROR));

        $credentialsResponse = $this->createMockResponseFromJson([
            'data' => [
                ['id' => '12', 'type' => 'postgres', 'name' => 'prod-db-v2'],
                ['id' => '44', 'type' => 'slackApi', 'name' => 'slack-notifications'],
            ],
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($credentialsResponse);

        $cwd = getcwd();
        $originalDir = $cwd !== false ? $cwd : '/';
        chdir($this->testDir);

        try {
            $command = $this->createCommandWithHelperSet();
            $tester = new CommandTester($command);
            $outputPath = $this->testDir . '/patched.json';

            $exitCode = $tester->execute([
                'workflow' => $this->workflowPath,
                '--map' => $this->mapPath,
                '--output' => $outputPath,
            ], ['interactive' => false]);

            $this->assertSame(0, $exitCode);
            $patchedContent = file_get_contents($outputPath);
            $this->assertIsString($patchedContent);
            $patchedData = json_decode($patchedContent, true);
            $this->assertIsArray($patchedData);
            $this->assertSame('12', $patchedData['nodes'][0]['credentials']['postgres']['id']);
        } finally {
            chdir($originalDir);
        }
    }

    public function test_execute_no_patch_mode_only_validates(): void
    {
        if (@file_put_contents($this->envPath, "NGRAMX_N8N_HOST=http://localhost\nNGRAMX_N8N_PORT=5678\nNGRAMX_N8N_API_KEY=test-key\n") === false) {
            $this->markTestSkipped('Cannot write .env file (sandbox restrictions)');
        }

        file_put_contents($this->workflowPath, $this->createWorkflowWithCredentials());

        $credentialsResponse = $this->createMockResponseFromJson([
            'data' => [
                ['id' => '12', 'type' => 'postgres', 'name' => 'prod-db'],
                ['id' => '44', 'type' => 'slackApi', 'name' => 'slack-notifications'],
            ],
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($credentialsResponse);

        $cwd = getcwd();
        $originalDir = $cwd !== false ? $cwd : '/';
        chdir($this->testDir);

        try {
            $command = $this->createCommandWithHelperSet();
            $tester = new CommandTester($command);

            $exitCode = $tester->execute([
                'workflow' => $this->workflowPath,
                '--no-patch' => true,
            ], ['interactive' => false]);

            $this->assertSame(0, $exitCode);
            $output = $tester->getDisplay();
            // Should contain report but not workflow JSON
            $this->assertStringContainsString('normalise:', $output);
            $this->assertStringNotContainsString('"id"', $output); // No workflow JSON
        } finally {
            chdir($originalDir);
        }
    }

    public function test_execute_dry_run_mode_shows_report(): void
    {
        if (@file_put_contents($this->envPath, "NGRAMX_N8N_HOST=http://localhost\nNGRAMX_N8N_PORT=5678\nNGRAMX_N8N_API_KEY=test-key\n") === false) {
            $this->markTestSkipped('Cannot write .env file (sandbox restrictions)');
        }

        file_put_contents($this->workflowPath, $this->createWorkflowWithCredentials());

        $credentialsResponse = $this->createMockResponseFromJson([
            'data' => [
                ['id' => '12', 'type' => 'postgres', 'name' => 'prod-db'],
                ['id' => '44', 'type' => 'slackApi', 'name' => 'slack-notifications'],
            ],
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($credentialsResponse);

        $cwd = getcwd();
        $originalDir = $cwd !== false ? $cwd : '/';
        chdir($this->testDir);

        try {
            $command = $this->createCommandWithHelperSet();
            $tester = new CommandTester($command);

            $exitCode = $tester->execute([
                'workflow' => $this->workflowPath,
                '--dry-run' => true,
            ], ['interactive' => false]);

            $this->assertSame(0, $exitCode);
            $output = $tester->getDisplay();
            $this->assertStringContainsString('Dry run', $output);
        } finally {
            chdir($originalDir);
        }
    }

    public function test_execute_json_report_format(): void
    {
        if (@file_put_contents($this->envPath, "NGRAMX_N8N_HOST=http://localhost\nNGRAMX_N8N_PORT=5678\nNGRAMX_N8N_API_KEY=test-key\n") === false) {
            $this->markTestSkipped('Cannot write .env file (sandbox restrictions)');
        }

        file_put_contents($this->workflowPath, $this->createWorkflowWithCredentials());

        $credentialsResponse = $this->createMockResponseFromJson([
            'data' => [
                ['id' => '12', 'type' => 'postgres', 'name' => 'prod-db'],
                ['id' => '44', 'type' => 'slackApi', 'name' => 'slack-notifications'],
            ],
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($credentialsResponse);

        $cwd = getcwd();
        $originalDir = $cwd !== false ? $cwd : '/';
        chdir($this->testDir);

        try {
            $command = $this->createCommandWithHelperSet();
            $tester = new CommandTester($command);

            $exitCode = $tester->execute([
                'workflow' => $this->workflowPath,
                '--report' => 'json',
                '--no-patch' => true,
            ], ['interactive' => false]);

            $this->assertSame(0, $exitCode);
            $output = $tester->getDisplay();
            // Extract JSON from output (may have other text from setupEnvironment)
            if (preg_match('/\{.*\}/s', $output, $matches)) {
                $decoded = json_decode($matches[0], true);
                $this->assertIsArray($decoded);
                $this->assertArrayHasKey('summary', $decoded);
                $this->assertArrayHasKey('credentials', $decoded);
            } else {
                $this->fail('No JSON found in output: ' . $output);
            }
        } finally {
            chdir($originalDir);
        }
    }
}
