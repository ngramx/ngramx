<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema\Postmaclone;

/**
 * Optional overrides for `ngramx postmaclone connect` (SSH tunnel + docker host).
 */
readonly class ConnectConfig
{
    public const DEFAULT_DOCKER_HOST = 'host.docker.internal';

    public function __construct(
        public ?string $tunnelHost = null,
        public ?string $tunnelUser = null,
        public ?int $tunnelPort = null,
        public ?int $localPort = null,
        public string $dockerHost = self::DEFAULT_DOCKER_HOST,
    ) {
    }
}
