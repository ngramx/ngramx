<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema\Postmaclone;

/**
 * Factory publish destination for anonymized dump artifacts.
 */
readonly class PublishConfig
{
    public function __construct(
        public ?string $path = null,
        public ?string $region = null,
        public ?string $endpoint = null,
        public ?bool $pathStyle = null,
        public ?string $file = null,
        public ?BackupCredentialsConfig $credentials = null,
    ) {
    }
}
