<?php

declare(strict_types=1);

namespace Ngramx\Http;

/**
 * Rewrite a URL so the TCP connection goes to loopback while the HTTP Host
 * (and TLS SNI, via {@see AppUrlProbe}) still use the original hostname.
 *
 * WSL's resolver does not treat `*.localhost` as loopback (unlike Windows
 * browsers per RFC 6761). Probing the hostname URL therefore fails with
 * "connection refused" even when nginx is healthy on 127.0.0.1.
 */
final class LoopbackUrl
{
    /**
     * When $url's host is `localhost` or `*.localhost`, return a loopback
     * connect target. Otherwise null — probe the URL as written.
     *
     * @return array{url: string, host: string}|null
     */
    public static function probeTarget(string $url): ?array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $host = (string) $parts['host'];
        if (!self::isLocalhostFamilyHostname($host)) {
            return null;
        }

        $scheme = (string) $parts['scheme'];
        $port = $parts['port'] ?? self::defaultPortForScheme($scheme);
        if ($port === null) {
            return null;
        }

        $path = $parts['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return [
            'url' => sprintf('%s://127.0.0.1:%d%s%s%s', $scheme, $port, $path, $query, $fragment),
            'host' => $host,
        ];
    }

    public static function isLocalhostFamilyHostname(string $host): bool
    {
        $h = strtolower($host);

        return $h === 'localhost' || str_ends_with($h, '.localhost');
    }

    /**
     * Replace the host component of a URL, preserving scheme, port, path,
     * query and fragment.
     */
    public static function withHost(string $url, string $host): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return $url;
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $userinfo = isset($parts['user'])
            ? $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@'
            : '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $scheme . $userinfo . $host . $port . $path . $query . $fragment;
    }

    private static function defaultPortForScheme(string $scheme): ?int
    {
        return match (strtolower($scheme)) {
            'http' => 80,
            'https' => 443,
            default => null,
        };
    }
}
