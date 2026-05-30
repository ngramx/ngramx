<?php

declare(strict_types=1);

namespace Ngramx\Executor;

use Ngramx\Docker\ContainerExecutor;
use Ngramx\Executor\Result\ParallelCommandResult;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs a group of docker-compose exec commands concurrently.
 *
 * Each entry is started as its own `Process` and polled until completion. Output
 * is streamed to caller-supplied callbacks so a live UI can render per-command
 * progress; when each process finishes a finish callback is invoked, letting the
 * UI remove that slot.
 */
class ParallelContainerExecutor
{
    private const POLL_INTERVAL_MICROS = 50_000;

    public function __construct(
        private readonly ContainerExecutor $containerExecutor,
        private readonly string $composeFile,
        private readonly string $service,
        private readonly ?string $projectName = null,
    ) {
    }

    /**
     * Run every item in $items concurrently and block until they all finish.
     *
     * @param list<array{label: string, command: string, timeout: int}> $items
     * @param callable|null $onOutput    fn(string $label, string $line): void, invoked per complete line.
     * @param callable|null $onStart     fn(string $label): void, invoked once per item just before it starts.
     * @param callable|null $onFinish    fn(ParallelCommandResult $result): void, invoked when an item ends.
     * @return list<ParallelCommandResult> results in the same order as $items
     */
    public function runAll(
        array $items,
        ?callable $onOutput = null,
        ?callable $onStart = null,
        ?callable $onFinish = null,
    ): array {
        /** @var array<int, Process> $processes */
        $processes = [];
        /** @var array<int, float> $startTimes */
        $startTimes = [];
        /** @var array<int, string> $pendingBuffers */
        $pendingBuffers = [];
        /** @var array<int, list<string>> $capturedLines */
        $capturedLines = [];
        /** @var array<int, ParallelCommandResult|null> $results */
        $results = [];
        /** @var array<int, bool> $timedOutFlags */
        $timedOutFlags = [];

        foreach ($items as $index => $item) {
            $argv = $this->containerExecutor->buildExecCommand(
                composeFile: $this->composeFile,
                service: $this->service,
                command: $item['command'],
                projectName: $this->projectName,
            );

            $process = new Process($argv);
            $process->setTimeout($item['timeout']);

            $pendingBuffers[$index] = '';
            $capturedLines[$index] = [];
            $results[$index] = null;
            $timedOutFlags[$index] = false;

            $label = $item['label'];
            $process->start(function (string $type, string $buffer) use (&$pendingBuffers, &$capturedLines, $index, $label, $onOutput): void {
                $pendingBuffers[$index] .= $buffer;
                while (true) {
                    $pos = strcspn($pendingBuffers[$index], "\r\n");
                    if ($pos === strlen($pendingBuffers[$index])) {
                        return;
                    }

                    $rawLine = substr($pendingBuffers[$index], 0, $pos);
                    $pendingBuffers[$index] = substr($pendingBuffers[$index], $pos + 1);

                    $line = self::stripAnsi($rawLine);
                    $line = str_replace(["\r", "\t"], ['', '  '], $line);
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }

                    $capturedLines[$index][] = $line;

                    if ($onOutput !== null) {
                        $onOutput($label, $line);
                    }
                }
            });

            $processes[$index] = $process;
            $startTimes[$index] = microtime(true);

            if ($onStart !== null) {
                $onStart($label);
            }
        }

        $remaining = array_keys($processes);

        while ($remaining !== []) {
            foreach ($remaining as $i => $index) {
                $process = $processes[$index];

                try {
                    $process->checkTimeout();
                } catch (ProcessTimedOutException) {
                    $timedOutFlags[$index] = true;
                }

                if ($process->isRunning()) {
                    continue;
                }

                // Flush any pending partial line remaining in the buffer.
                $remainder = rtrim($pendingBuffers[$index], "\r\n");
                if ($remainder !== '') {
                    $line = trim(str_replace(["\r", "\t"], ['', '  '], self::stripAnsi($remainder)));
                    if ($line !== '') {
                        $capturedLines[$index][] = $line;
                        if ($onOutput !== null) {
                            $onOutput($items[$index]['label'], $line);
                        }
                    }
                    $pendingBuffers[$index] = '';
                }

                $result = new ParallelCommandResult(
                    label: $items[$index]['label'],
                    command: $items[$index]['command'],
                    exitCode: $process->getExitCode() ?? -1,
                    executionTime: microtime(true) - $startTimes[$index],
                    successful: $process->isSuccessful() && !$timedOutFlags[$index],
                    outputLines: $capturedLines[$index],
                    timedOut: $timedOutFlags[$index],
                );

                $results[$index] = $result;

                if ($onFinish !== null) {
                    $onFinish($result);
                }

                unset($remaining[$i]);
            }

            if ($remaining !== []) {
                usleep(self::POLL_INTERVAL_MICROS);
            }
        }

        /** @var list<ParallelCommandResult> $ordered */
        $ordered = [];
        foreach ($items as $index => $_) {
            $res = $results[$index];
            \assert($res !== null);
            $ordered[] = $res;
        }
        return $ordered;
    }

    private static function stripAnsi(string $line): string
    {
        return preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $line) ?? $line;
    }
}
