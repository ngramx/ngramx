<?php

declare(strict_types=1);

namespace Ngramx\Laravel;

readonly class LogEntry
{
    public function __construct(
        public \DateTimeImmutable $timestamp,
        public string $level,
        public string $message,
        public ?string $stackTrace = null,
    ) {
    }
}
