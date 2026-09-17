<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Backup;

use Ngramx\Filesystem\HostBinary;

/**
 * Debian/Ubuntu install both /usr/bin/pg_dump (pg_wrapper) and
 * /usr/lib/postgresql/{N}/bin/pg_dump. The wrapper often keeps an older
 * default after a newer client is installed (factory run 35177394530:
 * client 17 on disk, wrapper still 16.15).
 */
final class PostgresDumpBinary
{
    public static function resolve(string $libRoot = '/usr/lib/postgresql'): string
    {
        $versioned = self::newestVersioned($libRoot, 'pg_dump');
        if ($versioned !== null) {
            return $versioned;
        }

        return HostBinary::find('pg_dump') ?? 'pg_dump';
    }

    public static function newestVersioned(string $libRoot, string $name): ?string
    {
        $pattern = rtrim($libRoot, '/') . '/*/bin/' . $name;
        $matches = glob($pattern);
        if ($matches === false || $matches === []) {
            return null;
        }

        usort($matches, static fn (string $a, string $b): int => self::versionFromPath($a) <=> self::versionFromPath($b));

        return $matches[array_key_last($matches)];
    }

    public static function versionFromPath(string $path): int
    {
        if (preg_match('#/(\d+)/bin/#', $path, $m) !== 1) {
            return 0;
        }

        return (int) $m[1];
    }
}
