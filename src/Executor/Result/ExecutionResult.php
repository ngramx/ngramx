<?php

declare(strict_types=1);

namespace Ngramx\Executor\Result;

use Symfony\Component\Process\Process;

readonly class ExecutionResult
{
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $errorOutput,
        public bool $successful,
        public float $executionTime,
    ) {
    }

    public static function fromProcess(Process $process): self
    {
        return new self(
            exitCode: $process->getExitCode() ?? -1,
            output: $process->getOutput(),
            errorOutput: $process->getErrorOutput(),
            successful: $process->isSuccessful(),
            executionTime: 0.0, // Will be set by caller
        );
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }
}
