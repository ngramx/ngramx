<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema;

readonly class ServiceWaitConfig
{
    /**
     * @param string      $service       Compose service name to wait for.
     * @param int         $timeout       Per-service readiness budget in seconds.
     * @param bool        $healthcheck   When true, prefer the container's Docker
     *                                   healthcheck (waits for `.State.Health.Status`
     *                                   == healthy) if one is defined.
     * @param string|null $readyCommand  Command executed inside the container that
     *                                   must exit 0 for the service to be ready
     *                                   (e.g. `php artisan --version`).
     * @param string|null $readyLog      Regex matched against the container's recent
     *                                   logs; a match marks the service ready
     *                                   (e.g. `is ready!`).
     */
    public function __construct(
        public string $service,
        public int $timeout,
        public bool $healthcheck = false,
        public ?string $readyCommand = null,
        public ?string $readyLog = null,
    ) {
    }

    /**
     * Whether any explicit readiness probe (healthcheck flag, ready command or
     * ready log) has been configured for this service. When false, readiness
     * falls back to the weak "container is running" signal.
     */
    public function hasExplicitProbe(): bool
    {
        return $this->healthcheck
            || $this->readyCommand !== null
            || $this->readyLog !== null;
    }
}
