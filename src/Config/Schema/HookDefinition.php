<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema;

/**
 * A single host command to run when a {@see HookEvent} fires.
 */
readonly class HookDefinition
{
    public const DEFAULT_TIMEOUT = 120;

    public function __construct(
        public string $command,
        public string $description = '',
        public int $timeout = self::DEFAULT_TIMEOUT,
        public bool $ignoreFailure = true,
        public ?string $cwd = null,
    ) {
    }
}
