<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema;

readonly class DockerConfig
{
    /**
     * @param ServiceWaitConfig[] $waitFor
     */
    public function __construct(
        public string $composeFile,
        public string $primaryService,
        public string $appUrl,
        public array $waitFor = [],
        public string $sslPath = 'docker/nginx/ssl',
    ) {
    }
}
