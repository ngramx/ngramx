<?php

declare(strict_types=1);

namespace Ngramx\Worktree;

use Ngramx\Http\AppUrlProbe;
use Ngramx\Http\UrlPortOffset;

/**
 * Chooses the URL a worktree review should advertise.
 *
 * Two strategies, picked automatically per app:
 *
 *   - Subdomain: "<folder>.localhost:<offset-port>". Browsers resolve any
 *     "*.localhost" name to loopback with no /etc/hosts entry, giving each
 *     ticket a distinct, isolated origin (cookies/sessions don't collide).
 *     This only works when the app serves regardless of the Host header.
 *
 *   - Fallback: the app's own host with the port offset applied
 *     (e.g. "dev.hydra:8080"). Required for apps that route by Host/ServerName
 *     (apache/nginx name-based vhosts), which 404 on an unknown hostname.
 *
 * We can't know which an app is without asking it, so once the stack is up we
 * fire a differential probe at the loopback port: if the app answers the
 * invented subdomain exactly as it answers its configured host, it's
 * host-agnostic and we use the pretty subdomain; otherwise it routes by Host
 * and we fall back to its own host.
 */
class WorktreeUrlResolver
{
    private readonly AppUrlProbe $probe;

    /**
     * @param int $baselineAttempts How many times to probe the app's own host
     *        before deciding, to tolerate a still-warming container. Lower it in
     *        tests to avoid the inter-attempt backoff.
     */
    public function __construct(
        ?AppUrlProbe $probe = null,
        private readonly int $baselineAttempts = 3,
    ) {
        // Short timeouts: the stack is already up by the time we probe, so a
        // slow response means "not this hostname" rather than "still booting".
        $this->probe = $probe ?? new AppUrlProbe(connectTimeout: 2.0, requestTimeout: 3.0);
    }

    /**
     * Resolve the worktree URL for a started environment. Returns the app's own
     * host + offset port when the app is host-routed (or unreachable), and the
     * "<folder>.localhost" subdomain when the app is host-agnostic.
     */
    public function resolve(string $appUrl, string $folderName, int $portOffset): string
    {
        $fallback = UrlPortOffset::apply($appUrl, $portOffset);

        $parts = parse_url($fallback);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $fallback;
        }

        $scheme = (string) $parts['scheme'];
        $realHost = (string) $parts['host'];
        $port = isset($parts['port'])
            ? (int) $parts['port']
            : ($scheme === 'https' ? 443 : 80);

        $subHost = WorktreeIdentity::sanitizeSegment($folderName) . '.localhost';

        // app_url is already a loopback subdomain — nothing to probe or swap.
        if (strcasecmp($realHost, $subHost) === 0) {
            return $fallback;
        }

        $probeUrl = sprintf('%s://127.0.0.1:%d/', $scheme, $port);

        // Establish the app's "own host" baseline first (retried, since the
        // stack may still be warming), then ask it about the invented subdomain.
        $real = $this->probe->probeWithHost($probeUrl, $realHost, attempts: max(1, $this->baselineAttempts));
        $sub = $this->probe->probeWithHost($probeUrl, $subHost, attempts: 1);

        if ($sub->statusCode !== null && $sub->statusCode === $real->statusCode) {
            return $this->withHost($fallback, $subHost);
        }

        return $fallback;
    }

    /**
     * Replace the host component of a URL, preserving scheme, port, path,
     * query and fragment.
     */
    private function withHost(string $url, string $host): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $url;
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $scheme . $host . $port . $path . $query . $fragment;
    }
}
