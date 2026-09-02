<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema\Postmaclone;

/**
 * Per-engine connection credentials for factory produce (scratch) and shared hosted DB (anon).
 */
readonly class EngineConnectionsConfig
{
    public function __construct(
        public ?DbCredentialsConfig $scratch = null,
        public ?DbCredentialsConfig $anon = null,
    ) {
    }
}
