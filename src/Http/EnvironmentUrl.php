<?php

declare(strict_types=1);

namespace Ngramx\Http;

use Ngramx\Config\LockFileData;
use Ngramx\Docker\PortOffsetManager;

/**
 * Works out the host-facing URL of a started environment: the configured
 * `docker.app_url` carrying the host port that environment is actually
 * listening on.
 *
 * Shared by `show-url`, `status` and `worktree --list` so the URL a developer
 * is told to open is the same one everywhere, whichever command they asked.
 */
final class EnvironmentUrl
{
    private readonly PortOffsetManager $portOffsetManager;

    public function __construct(?PortOffsetManager $portOffsetManager = null)
    {
        $this->portOffsetManager = $portOffsetManager ?? new PortOffsetManager();
    }

    /**
     * Resolve the URL for one environment.
     *
     * A URL recorded in the lock file wins outright: for worktrees the hostname
     * half of it was decided by probing the running app, which cannot be
     * re-derived from config.
     */
    public function resolve(
        string $appUrl,
        string $composeFile,
        string $primaryService,
        ?LockFileData $lock
    ): string {
        if ($lock !== null && $lock->url !== null && $lock->url !== '') {
            return $lock->url;
        }

        $scheme = strtolower((string) (parse_url($appUrl, PHP_URL_SCHEME) ?: 'http'));
        $defaultPort = $this->defaultPortForScheme($scheme);

        $basePort = $this->resolveBasePort($appUrl, $composeFile, $primaryService, $defaultPort);
        if ($basePort === null) {
            return $appUrl;
        }

        // A targeted conflict remap recorded at `up` time takes precedence for
        // its ports; the global offset covers the rest.
        $portMap = $lock->portMap ?? [];
        $finalPort = $portMap[$basePort] ?? ($basePort + ($lock->portOffset ?? 0));

        // https://host:443 is just https://host — printing the scheme's own
        // default port back at the user is noise.
        return $finalPort === $defaultPort
            ? $this->rebuild($appUrl, null)
            : $this->rebuild($appUrl, $finalPort);
    }

    /**
     * Work out which host port the offset/remap should be applied to.
     *
     * Order of preference:
     *   1. An explicit port in `docker.app_url` — the project said so.
     *   2. The host port publishing the scheme's default container port
     *      (443 for https, 80 for http), preferring the primary service but
     *      accepting any service: the web port is often published by a proxy
     *      container rather than by the app itself.
     *   3. The primary service's first published port.
     *   4. The scheme default, so an app behind a host-port-less proxy still
     *      gets the offset applied.
     */
    private function resolveBasePort(
        string $appUrl,
        string $composeFile,
        string $primaryService,
        ?int $defaultPort
    ): ?int {
        $explicitPort = parse_url($appUrl, PHP_URL_PORT);
        if (is_int($explicitPort)) {
            return $explicitPort;
        }

        if ($defaultPort !== null) {
            $published = $this->portOffsetManager->findHostPortForInternalPort(
                $composeFile,
                $defaultPort,
                $primaryService,
            );

            if ($published !== null) {
                return $published;
            }
        }

        $primaryPort = $this->portOffsetManager->getPrimaryServicePort($composeFile, $primaryService);

        return $primaryPort ?? $defaultPort;
    }

    private function defaultPortForScheme(string $scheme): ?int
    {
        return match ($scheme) {
            'http' => 80,
            'https' => 443,
            default => null,
        };
    }

    /**
     * Rebuild $baseUrl with (or, for a null port, without) a port component.
     */
    private function rebuild(string $baseUrl, ?int $port): string
    {
        $parsed = parse_url($baseUrl);

        $scheme = is_array($parsed) ? ($parsed['scheme'] ?? 'http') : 'http';
        $host = is_array($parsed) ? ($parsed['host'] ?? 'localhost') : 'localhost';
        $path = is_array($parsed) ? ($parsed['path'] ?? '') : '';

        $portPart = $port === null ? '' : ':' . $port;

        return "{$scheme}://{$host}{$portPart}{$path}";
    }
}
