<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema;

readonly class CommandDefinition
{
    /**
     * @param list<string> $commands Normalized list of commands to execute. Always contains at least one entry.
     *                               For single-command entries this mirrors $command; for multi-command entries it
     *                               contains the full list of sub-commands in declaration order.
     * @param bool $parallel When a list of commands is given, controls whether they run concurrently (true,
     *                        the default) or one after another, stopping on the first failure (false). Ignored
     *                        for single-command entries.
     */
    public function __construct(
        public string $command,
        public string $description,
        public int $timeout = 600,
        public int $retry = 0,
        public bool $ignoreFailure = false,
        public array $commands = [],
        public bool $parallel = true,
    ) {
    }

    /**
     * True when this command should run its sub-commands concurrently. Only
     * multi-command entries that have not opted out via `parallel: false`.
     */
    public function isParallel(): bool
    {
        return $this->hasMultipleCommands() && $this->parallel;
    }

    /**
     * True when this command should run its sub-commands one after another,
     * stopping on the first failure (a multi-command entry with `parallel: false`).
     */
    public function isSequentialList(): bool
    {
        return $this->hasMultipleCommands() && !$this->parallel;
    }

    public function hasMultipleCommands(): bool
    {
        return count($this->commands) > 1;
    }
}
