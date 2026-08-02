<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Connection;

use Ngramx\Config\Schema\Postmaclone\PostmacloneConfig;
use Ngramx\Postmaclone\Exception\PostmacloneException;

final class PdoDriverGuard
{
    public static function assertForEngine(string $engine): void
    {
        $driver = match ($engine) {
            PostmacloneConfig::ENGINE_POSTGRES => 'pdo_pgsql',
            PostmacloneConfig::ENGINE_MYSQL, PostmacloneConfig::ENGINE_MARIADB => 'pdo_mysql',
            default => null,
        };

        if ($driver === null) {
            return;
        }

        if (!in_array($driver, \PDO::getAvailableDrivers(), true) && !extension_loaded($driver)) {
            $hint = $driver === 'pdo_pgsql'
                ? 'Install the PostgreSQL PHP extension (e.g. sudo apt install php-pgsql or php8.4-pgsql).'
                : 'Install the MySQL PHP extension (e.g. sudo apt install php-mysql).';

            throw new PostmacloneException(
                "PHP is missing the {$driver} driver (required for Post Maclone anonymization).\n{$hint}"
            );
        }
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public static function doctorCheck(string $engine): array
    {
        try {
            self::assertForEngine($engine);
            $driver = $engine === PostmacloneConfig::ENGINE_POSTGRES ? 'pdo_pgsql' : 'pdo_mysql';

            return ['ok' => true, 'message' => "PHP {$driver} driver available"];
        } catch (PostmacloneException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
