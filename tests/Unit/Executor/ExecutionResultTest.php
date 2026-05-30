<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Executor;

use Ngramx\Executor\Result\ExecutionResult;
use PHPUnit\Framework\TestCase;

class ExecutionResultTest extends TestCase
{
    public function test_it_creates_result_with_all_properties(): void
    {
        $result = new ExecutionResult(
            exitCode: 0,
            output: 'Success output',
            errorOutput: '',
            successful: true,
            executionTime: 1.5
        );

        $this->assertEquals(0, $result->exitCode);
        $this->assertEquals('Success output', $result->output);
        $this->assertEquals('', $result->errorOutput);
        $this->assertTrue($result->successful);
        $this->assertEquals(1.5, $result->executionTime);
    }

    public function test_is_successful_returns_correct_value(): void
    {
        $successResult = new ExecutionResult(
            exitCode: 0,
            output: '',
            errorOutput: '',
            successful: true,
            executionTime: 0.5
        );

        $failureResult = new ExecutionResult(
            exitCode: 1,
            output: '',
            errorOutput: 'Error',
            successful: false,
            executionTime: 0.5
        );

        $this->assertTrue($successResult->isSuccessful());
        $this->assertFalse($failureResult->isSuccessful());
    }
}
