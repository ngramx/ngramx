<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema\Postmaclone;

/**
 * Long-lived hosted database refreshed by factory produce (write side)
 * or pointed at by app ngramx.yml for direct consumer access (read side).
 */
readonly class SharedDbConfig
{
    public const DEFAULT_PASSWORD_ROTATION_DAYS = 7;

    public function __construct(
        public ?DbConnectionConfig $connection = null,
        /** Fail consumer create if shared DB was not refreshed within this many hours. Null = no check. */
        public ?int $maxAgeHours = null,
        /** Rotate the DB password and update 1Password every N days. Null/default 7; 0 disables. */
        public ?int $passwordRotationDays = 7,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->connection !== null && $this->connection->isConfigured();
    }
}
