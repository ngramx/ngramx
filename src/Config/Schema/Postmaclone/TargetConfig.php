<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema\Postmaclone;

readonly class TargetConfig
{
    public const PROVIDER_NEON = 'neon';
    public const PROVIDER_DOCKER = 'docker';
    public const PROVIDER_AUTO = 'auto';

    public const DEFAULT_TTL_HOURS = 4;

    public function __construct(
        public string $provider = self::PROVIDER_AUTO,
        public int $ttlHours = self::DEFAULT_TTL_HOURS,
        public ?string $neonProjectId = null,
        public ?string $neonRegionId = null,
        public ?string $dockerImage = null,
        public int $dockerPort = 0,
    ) {
    }
}
