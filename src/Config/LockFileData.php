<?php

declare(strict_types=1);

namespace Ngramx\Config;

/**
 * Value object representing lock file data
 */
readonly class LockFileData
{
    public function __construct(
        public ?string $namespace,
        public ?int $portOffset,
        public string $startedAt,
        public bool $noHostMapping = false,
        public bool $herdStopped = false,
        public bool $caddyStopped = false,
    ) {
    }
}
