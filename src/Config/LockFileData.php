<?php

declare(strict_types=1);

namespace Ngramx\Config;

/**
 * Value object representing lock file data
 */
readonly class LockFileData
{
    /**
     * @param array<int, int> $portMap Per-port conflict remap applied at startup
     *        (conflicted base host port => replacement). Empty when no ports
     *        were remapped.
     * @param ?string $url The URL the environment was advertised on at startup.
     *        Recorded so `ngramx show-url` can reproduce it exactly — notably
     *        for worktrees, whose URL is resolved by probing the running app
     *        (subdomain vs canonical host) and cannot be re-derived offline.
     */
    public function __construct(
        public ?string $namespace,
        public ?int $portOffset,
        public string $startedAt,
        public bool $noHostMapping = false,
        public bool $herdStopped = false,
        public bool $caddyStopped = false,
        public array $portMap = [],
        public ?string $url = null,
    ) {
    }
}
