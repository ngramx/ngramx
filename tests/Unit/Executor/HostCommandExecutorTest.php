<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Executor;

use Ngramx\Config\Schema\CommandDefinition;
use Ngramx\Executor\HostCommandExecutor;
use PHPUnit\Framework\TestCase;

class HostCommandExecutorTest extends TestCase
{
    private HostCommandExecutor $executor;

    protected function setUp(): void
    {
        $this->executor = new HostCommandExecutor();
    }

    public function test_it_executes_successful_command(): void
    {
        $cmd = new CommandDefinition(
            command: 'echo "Hello World"',
            description: 'Test command',
            timeout: 10
        );

        $result = $this->executor->execute($cmd);

        $this->assertTrue($result->isSuccessful());
        $this->assertEquals(0, $result->exitCode);
        $this->assertStringContainsString('Hello World', $result->output);
    }

    public function test_it_handles_failed_command(): void
    {
        $cmd = new CommandDefinition(
            command: 'exit 1',
            description: 'Failing command',
            timeout: 10
        );

        $result = $this->executor->execute($cmd);

        $this->assertFalse($result->isSuccessful());
        $this->assertEquals(1, $result->exitCode);
    }

    public function test_it_captures_output(): void
    {
        $cmd = new CommandDefinition(
            command: 'echo "Line 1" && echo "Line 2"',
            description: 'Multi-line output',
            timeout: 10
        );

        $result = $this->executor->execute($cmd);

        $this->assertStringContainsString('Line 1', $result->output);
        $this->assertStringContainsString('Line 2', $result->output);
    }

    public function test_it_measures_execution_time(): void
    {
        $cmd = new CommandDefinition(
            command: 'sleep 0.1',
            description: 'Slow command',
            timeout: 10
        );

        $result = $this->executor->execute($cmd);

        $this->assertGreaterThan(0, $result->executionTime);
        $this->assertLessThan(1, $result->executionTime); // Should be less than 1 second
    }

    public function test_it_respects_timeout(): void
    {
        $cmd = new CommandDefinition(
            command: 'sleep 10',
            description: 'Long command',
            timeout: 1 // 1 second timeout
        );

        $result = $this->executor->execute($cmd);

        // Command should fail due to timeout
        $this->assertFalse($result->isSuccessful());
    }
}
