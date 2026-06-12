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
