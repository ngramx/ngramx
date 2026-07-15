<?php

declare(strict_types=1);

namespace Ngramx\Http;

/**
 * Apply a numeric port offset to a URL, returning a new URL that targets
 * `(scheme_default_port + offset)` on the same host.
 *
 * Used by `ngramx up` when `--avoid-conflicts` / `--port-offset` shift the
 * stack onto non-default ports: the probe URL and the URL we print to the
 * user both need to follow the shift, otherwise we end up probing the
 * original 443 while the stack is actually listening on 8543.
 */
final class UrlPortOffset
{
    /**
     * Return $url with its port shifted by $offset. URLs that already
     * carry an explicit `:port` use it as the base; URLs that don't get
     * the scheme's default (http → 80, https → 443). Returns the original
     * URL unchanged when $offset <= 0 or when the URL is unparseable.
     */
    public static function apply(string $url, int $offset): string
    {
        if ($offset <= 0) {
            return $url;
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $basePort = $parts['port'] ?? self::defaultPortForScheme((string) $parts['scheme']);
        if ($basePort === null) {
            return $url;
        }

        return self::rebuild($parts, $basePort + $offset);
    }

    /**
     * Return $url with its port swapped when the URL's effective port (explicit
     * `:port`, or the scheme default) appears in the per-port remap. Used when
     * targeted conflict resolution moved individual host ports: the printed and
     * probed URL must follow the web port wherever it went, while URLs whose
     * port did not conflict stay untouched.
     *
     * @param array<int, int> $portMap conflicted base host port => replacement
     */
    public static function applyMap(string $url, array $portMap): string
    {
        if ($portMap === []) {
            return $url;
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $basePort = $parts['port'] ?? self::defaultPortForScheme((string) $parts['scheme']);
        if ($basePort === null || !isset($portMap[$basePort])) {
            return $url;
        }

        return self::rebuild($parts, $portMap[$basePort]);
    }

    private static function defaultPortForScheme(string $scheme): ?int
    {
        return match (strtolower($scheme)) {
            'http' => 80,
            'https' => 443,
            default => null,
        };
    }

    /**
     * @param array{scheme?: string, host?: string, port?: int, user?: string, pass?: string, path?: string, query?: string, fragment?: string} $parts
     */
    private static function rebuild(array $parts, int $newPort): string
    {
        $scheme = $parts['scheme'] ?? '';
        $userinfo = isset($parts['user'])
            ? $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@'
            : '';
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return sprintf(
            '%s://%s%s:%d%s%s%s',
            $scheme,
            $userinfo,
            $host,
            $newPort,
            $path,
            $query,
            $fragment,
        );
    }
}
