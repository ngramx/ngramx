<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema\Postmaclone;

/**
 * 1Password secret references (op://…) for Spaces/S3 — never plaintext secrets.
 */
readonly class BackupCredentialsConfig
{
    public function __construct(
        public string $key,
        public string $secret,
    ) {
    }
}
