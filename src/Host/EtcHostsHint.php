<?php

declare(strict_types=1);

namespace Ngramx\Host;

/**
 * Detects when docker.app_url uses a hostname that will not resolve on the developer's
 * machine without /etc/hosts (or similar), and suggests a line to add.
 */
final class EtcHostsHint
{
    /**
     * If the app URL hostname does not resolve via DNS, returns the recommended /etc/hosts line.
     * Returns null when no hint is needed (localhost, already resolves, etc.).
     */
    public static function suggestedHostsLine(string $appUrl): ?string
    {
        $host = self::hostnameFromUrl($appUrl);
        if ($host === null || $host === '') {
            return null;
        }

        // Bare localhost / raw loopback IPs never need a hosts entry.
        $h = strtolower($host);
        if ($h === 'localhost' || $h === '127.0.0.1' || $h === '::1') {
            return null;
        }

        // Raw IPs: gethostbyname() returns the input unchanged, same as NXDOMAIN — do not suggest /etc/hosts.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        // *.localhost is loopback per RFC 6761 on many OSes (including Windows),
        // but WSL/Linux often does not resolve it — suggest a hosts line then.
        // ngramx up probes *.localhost via 127.0.0.1 regardless; this hint is
        // for curl/other tools inside the distro.
        $resolved = @gethostbyname($host);
        if ($resolved !== $host) {
            return null;
        }

        return '127.0.0.1 '.$host;
    }

    private static function hostnameFromUrl(string $appUrl): ?string
    {
        $parts = parse_url($appUrl);

        return isset($parts['host']) ? (string) $parts['host'] : null;
    }

}
