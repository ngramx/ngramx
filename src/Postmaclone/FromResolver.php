<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone;

use Ngramx\Postmaclone\Exception\PostmacloneException;

class FromResolver
{
    private const CONNECTION_SCHEMES = [
        'postgresql',
        'postgres',
        'mysql',
        'mariadb',
        'pdo_pgsql',
        'pdo_mysql',
    ];

    private const S3_SCHEMES = [
        's3',
        'spaces',
    ];

    public function resolve(string $from): FromSource
    {
        $from = trim($from);
        if ($from === '') {
            throw new PostmacloneException('--from value must not be empty');
        }

        if (preg_match('#^([a-zA-Z][a-zA-Z0-9+.-]*)://#', $from, $matches) === 1) {
            $scheme = strtolower($matches[1]);

            if (in_array($scheme, self::CONNECTION_SCHEMES, true)) {
                return new FromSource(
                    FromSource::KIND_CONNECTION,
                    $from,
                    $this->engineHintFromScheme($scheme),
                );
            }

            if (in_array($scheme, self::S3_SCHEMES, true)) {
                return new FromSource(FromSource::KIND_S3, $from);
            }

            throw new PostmacloneException(
                "Unsupported --from URI scheme '{$scheme}'. "
                . 'Use a dump path, postgresql:// / mysql:// connection string, or s3:// / spaces:// object URI.'
            );
        }

        return new FromSource(FromSource::KIND_PATH, $from);
    }

    private function engineHintFromScheme(string $scheme): string
    {
        return match ($scheme) {
            'postgresql', 'postgres', 'pdo_pgsql' => 'postgres',
            'mysql', 'pdo_mysql' => 'mysql',
            'mariadb' => 'mariadb',
            default => 'postgres',
        };
    }
}
