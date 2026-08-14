<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema\Postmaclone;

readonly class TargetConfig
{
    public const PROVIDER_NEON = 'neon';
    public const PROVIDER_DOCKER = 'docker';
    public const PROVIDER_REMOTE = 'remote';
    public const PROVIDER_AUTO = 'auto';

    public const DEFAULT_TTL_HOURS = 4;

    /**
     * Size above which auto prefers remote (when remote URL is configured) for prebuilt restores.
     * Bytes; default ~2 GiB.
     */
    public const DEFAULT_REMOTE_THRESHOLD_BYTES = 2147483648;

    public function __construct(
        /** auto: remote when a large artifact + remote URL exist; Neon for Postgres if NEON_API_KEY is set; otherwise Docker. */
        public string $provider = self::PROVIDER_AUTO,
        public int $ttlHours = self::DEFAULT_TTL_HOURS,
        public ?string $neonProjectId = null,
        public ?string $neonRegionId = null,
        public ?string $dockerImage = null,
        public int $dockerPort = 0,
        /** Connection URL for in-region DO/Neon restore host (op:// or literal from env). */
        public ?string $remoteUrl = null,
        public ?int $remoteThresholdBytes = self::DEFAULT_REMOTE_THRESHOLD_BYTES,
    ) {
    }
}
