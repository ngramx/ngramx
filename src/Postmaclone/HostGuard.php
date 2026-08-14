<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone;

use Ngramx\Postmaclone\Exception\PostmacloneException;

class HostGuard
{
    /**
     * @param list<string> $denyHosts glob-ish / substring patterns
     */
    public function assertAllowed(string $hostOrUrl, array $denyHosts): void
    {
        if ($denyHosts === []) {
            return;
        }

        $host = $hostOrUrl;
        if (str_contains($hostOrUrl, '://')) {
            $parsed = parse_url($hostOrUrl);
            $host = is_array($parsed) ? (string) ($parsed['host'] ?? $hostOrUrl) : $hostOrUrl;
        }

        $host = strtolower($host);
        foreach ($denyHosts as $pattern) {
            $pattern = strtolower(trim($pattern));
            if ($pattern === '') {
                continue;
            }
            if ($this->matches($host, $pattern)) {
                throw new PostmacloneException(
                    "Host '{$host}' is blocked by postmaclone.deny_hosts pattern '{$pattern}'"
                );
            }
        }
    }

    private function matches(string $host, string $pattern): bool
    {
        if (str_contains($pattern, '*')) {
            $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';

            return preg_match($regex, $host) === 1;
        }

        return $host === $pattern || str_ends_with($host, '.' . $pattern) || str_contains($host, $pattern);
    }
}
