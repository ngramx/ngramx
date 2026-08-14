<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema\Postmaclone;

/**
 * Consumer-side pointer at a published anonymized artifact (not a live shared DB).
 */
readonly class PrebuiltConfig
{
    public function __construct(
        public string $source = BackupConfig::SOURCE_S3,
        public ?string $path = null,
        public ?string $region = null,
        public ?string $endpoint = null,
        public ?bool $pathStyle = null,
        /** Basename or latest.json sibling object name. */
        public ?string $file = null,
        public ?BackupCredentialsConfig $credentials = null,
        /** Fail create if the published artifact is older than this (hours). Null = no check. */
        public ?int $maxAgeHours = null,
    ) {
    }
}
