<?php

declare(strict_types=1);

namespace Ngramx\Database;

/**
 * A TablePlus connection URL.
 *
 * TablePlus registers the driver schemes (`postgresql://`, `mysql://`,
 * `mariadb://`) and opens a connection when one is clicked. The `tableplus://`
 * scheme in the app is for its own screens, not for creating a connection.
 */
final class TablePlusUrl
{
    public static function build(
        string $engine,
        string $username,
        string $password,
        string $host,
        int $port,
        string $database,
        string $name,
    ): ?string {
        $scheme = self::scheme($engine);
        if ($scheme === null || $username === '' || $database === '' || $host === '' || $port <= 0) {
            return null;
        }

        $userinfo = rawurlencode($username);
        if ($password !== '') {
            $userinfo .= ':' . rawurlencode($password);
        }

        $query = http_build_query([
            'env' => 'local',
            'name' => $name,
        ], '', '&', PHP_QUERY_RFC3986);

        return sprintf(
            '%s://%s@%s:%d/%s?%s',
            $scheme,
            $userinfo,
            $host,
            $port,
            rawurlencode($database),
            $query,
        );
    }

    private static function scheme(string $engine): ?string
    {
        return match (strtolower(trim($engine))) {
            'postgres', 'postgresql', 'pgsql', 'pdo_pgsql' => 'postgresql',
            'mysql', 'pdo_mysql' => 'mysql',
            'mariadb' => 'mariadb',
            default => null,
        };
    }
}
