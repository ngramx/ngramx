<?php

declare(strict_types=1);

namespace Ngramx\Executor;

use Ngramx\Config\Schema\CommandDefinition;
use Ngramx\Docker\ContainerExecutor;
use Ngramx\Executor\Result\ExecutionResult;

class ContainerCommandExecutor
{
    public function __construct(
        private readonly ContainerExecutor $containerExecutor,
        private readonly string $composeFile,
        private readonly string $service,
        private readonly ?string $projectName = null,
    ) {
    }

    /**
     * Execute a command inside the Docker container
     *
     * @param callable|null $outputCallback Optional callback for real-time output
     */
    public function execute(CommandDefinition $cmd, ?callable $outputCallback = null): ExecutionResult
    {
        $startTime = microtime(true);

        $process = $this->containerExecutor->exec(
            composeFile: $this->composeFile,
            service: $this->service,
            command: $cmd->command,
            timeout: $cmd->timeout,
            outputCallback: $outputCallback,
            projectName: $this->projectName,
        );

        $executionTime = microtime(true) - $startTime;

        return new ExecutionResult(
            exitCode: $process->getExitCode() ?? -1,
            output: $process->getOutput(),
            errorOutput: $process->getErrorOutput(),
            successful: $process->isSuccessful(),
            executionTime: $executionTime,
        );
    }
}
