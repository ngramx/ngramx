<?php

declare(strict_types=1);

namespace Ngramx\Executor\Result;

/**
 * Result of a single sub-command executed as part of a parallel group.
 *
 * @phpstan-type LogLineList list<string>
 */
readonly class ParallelCommandResult
{
    /**
     * @param list<string> $outputLines Combined stdout/stderr lines (already newline-split, ANSI-stripped).
     */
    public function __construct(
        public string $label,
        public string $command,
        public int $exitCode,
        public float $executionTime,
        public bool $successful,
        public array $outputLines,
        public bool $timedOut = false,
    ) {
    }
}
