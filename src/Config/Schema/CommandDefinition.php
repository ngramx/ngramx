<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema;

readonly class CommandDefinition
{
    /**
     * @param list<string> $commands Normalized list of commands to execute. Always contains at least one entry.
     *                               For single-command entries this mirrors $command; for parallel entries it
     *                               contains the full list of sub-commands in declaration order.
     */
    public function __construct(
        public string $command,
        public string $description,
        public int $timeout = 600,
        public int $retry = 0,
        public bool $ignoreFailure = false,
        public array $commands = [],
    ) {
    }

    public function isParallel(): bool
    {
        return count($this->commands) > 1;
    }
}
