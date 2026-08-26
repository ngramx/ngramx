<?php

declare(strict_types=1);

namespace Ngramx\Http;

/**
 * Rewrite a deep-link's scheme/host/port onto the environment Ngramx is
 * actually running in.
 *
 * Completion records (`.ngramx/tickets/<id>/completion.json`) store test URLs
 * with a canonical host (e.g. `https://app.localhost/v/developers`), but that
 * host only resolves for the main checkout. When the same ticket is reviewed
 * in a worktree the stack lives on a different host/port — for example
 * `https://741-virginland.localhost:8743` — so the stored deep links point at
 * the wrong environment. This swaps the origin (scheme + host + port) for the
 * current environment's while preserving the path, query and fragment, so the
 * printed links always open the environment the command is operating against.
 */
final class CompletionUrlRewriter
{
    /**
     * Endpoint-aware variant: pick the endpoint whose canonical origin the
     * stored URL was written against and rewrite onto *that* endpoint's live
     * URL, so a PWA deep-link follows the PWA (not the Laravel app) into the
     * worktree. Unrecognised hosts fall back to the primary, as before.
     *
     * Matching prefers an exact scheme+host+port match, then host alone (two
     * endpoints on `localhost` with different ports still resolve correctly;
     * a bare `http://api.example.localhost` link with no port still finds the
     * API endpoint).
     */
    public static function rewriteEndpoints(string $url, EndpointUrls $canonical, EndpointUrls $live): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $target = $live->primary;
        $byHost = null;
        foreach ($canonical->all() as $name => $canonicalUrl) {
            $c = parse_url($canonicalUrl);
            if (!is_array($c) || !isset($c['host'])) {
                continue;
            }
            if (strcasecmp((string) $c['host'], (string) $parts['host']) !== 0) {
                continue;
            }
            if (self::effectivePort($c) === self::effectivePort($parts) && ($live->get($name) !== null)) {
                $target = (string) $live->get($name);
                $byHost = null;
                break;
            }
            $byHost ??= $live->get($name);
        }

        if ($byHost !== null) {
            $target = $byHost;
        }

        return self::rewrite($url, $target);
    }

    /**
     * @param array{scheme?: string, port?: int} $parts
     */
    private static function effectivePort(array $parts): int
    {
        if (isset($parts['port'])) {
            return (int) $parts['port'];
        }

        return strtolower((string) ($parts['scheme'] ?? 'http')) === 'https' ? 443 : 80;
    }

    /**
     * Return $url with its scheme/host/port replaced by those of $baseUrl,
     * keeping the original path, query and fragment.
     *
     * The URL is returned unchanged when:
     *   - it is not an http(s) URL (we only rewrite browser links), or
     *   - it cannot be parsed into a scheme + host, or
     *   - $baseUrl cannot be parsed into a scheme + host.
     */
    public static function rewrite(string $url, string $baseUrl): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        if (!in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            return $url;
        }

        $base = parse_url($baseUrl);
        if (!is_array($base) || !isset($base['scheme'], $base['host'])) {
            return $url;
        }

        $origin = (string) $base['scheme'] . '://' . (string) $base['host'];
        if (isset($base['port'])) {
            $origin .= ':' . (int) $base['port'];
        }

        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        $query = isset($parts['query']) ? '?' . (string) $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . (string) $parts['fragment'] : '';

        return $origin . $path . $query . $fragment;
    }
}
