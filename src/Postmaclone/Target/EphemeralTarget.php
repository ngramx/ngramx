<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Target;

readonly class EphemeralTarget
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public string $provider,
        public string $engine,
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        public string $password,
        public string $databaseUrl,
        public string $expiresAt,
        public array $meta = [],
    ) {
    }
}
