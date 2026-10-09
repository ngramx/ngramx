<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema;

/**
 * How `ngramx review` signs a reviewer into the running app.
 *
 * The default mints a single-use magic link through gigabyte/laravel-identity
 * for a seeded local user. A `url` template skips that and prints a
 * project-specific link instead.
 */
readonly class AuthBypassConfig
{
    public const DEFAULT_EMAIL = 'hello@gigabyte.software';

    /** Eight hours: long enough for a review sitting, short enough to expire. */
    public const DEFAULT_TTL_MINUTES = 480;

    public const MAX_TTL_MINUTES = 1440;

    public function __construct(
        public bool $enabled = true,
        public string $email = self::DEFAULT_EMAIL,
        public int $ttlMinutes = self::DEFAULT_TTL_MINUTES,
        public ?string $url = null,
    ) {
    }
}
