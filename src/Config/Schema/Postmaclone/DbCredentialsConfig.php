<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema\Postmaclone;

/**
 * Database login fields as op:// refs (or legacy literals for host/port in tests).
 *
 * 1Password database items use field names: server, port, username, password,
 * connection options (e.g. sslmode=require).
 */
readonly class DbCredentialsConfig
{
    public function __construct(
        public string $username,
        public string $password,
        /** op://…/server or op://…/host */
        public ?string $host = null,
        /** op://…/port */
        public ?string $port = null,
        /** op://…/connection options — parsed for sslmode when present */
        public ?string $connectionOptions = null,
    ) {
    }

    public function hasHost(): bool
    {
        return $this->host !== null && $this->host !== '';
    }
}
