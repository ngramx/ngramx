<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema;

/**
 * One additional browser-facing URL served by the same repository, alongside
 * `docker.app_url` (the primary endpoint): a supplier/customer sub-site behind
 * its own vhost, a PWA on a Vite dev server, an API on another hostname.
 *
 * Declared under `docker.endpoints.<name>`. The name is used as a DNS label
 * ("<name>.<folder>.localhost") for worktree environments, so it must be one.
 */
readonly class EndpointConfig
{
    /**
     * @param string               $name    DNS label identifying the endpoint (`api`, `pwa`).
     * @param string               $url     Canonical URL served by the main checkout.
     * @param string|null          $service Compose service that serves it; null means
     *                                      `docker.primary_service`.
     * @param array<string,string> $env     Env vars Ngramx keeps pointed at the live
     *                                      URLs — values may contain placeholders,
     *                                      see {@see \Ngramx\Http\EndpointUrls::expand()}.
     * @param string               $file    Project-relative env file the `env` entries
     *                                      are written to.
     */
    public function __construct(
        public string $name,
        public string $url,
        public ?string $service = null,
        public array $env = [],
        public string $file = '.env',
    ) {
    }

    public function serviceOr(string $primaryService): string
    {
        return $this->service ?? $primaryService;
    }
}
