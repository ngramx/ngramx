<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema;

readonly class DockerConfig
{
    /**
     * @param ServiceWaitConfig[] $waitFor
     * @param int|null            $verifyTimeout Budget in seconds for the post-start HTTP probe of
     *                                           `app_url`. The probe retries until the upstream
     *                                           returns a non-5xx response or this budget is
     *                                           exhausted. Null falls back to the orchestrator's
     *                                           built-in default (~60s). Bump it for projects whose
     *                                           cold boot (composer install / cache warm) routinely
     *                                           outlasts the default and 502s during startup.
     */
    public function __construct(
        public string $composeFile,
        public string $primaryService,
        public string $appUrl,
        public array $waitFor = [],
        public string $sslPath = 'docker/nginx/ssl',
        public ?int $verifyTimeout = null,
    ) {
    }
}
