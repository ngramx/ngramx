<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Anonymizer;

use Ngramx\Config\Schema\Postmaclone\PostmacloneConfig;

class SqlDialect
{
    public function __construct(
        private readonly string $engine,
    ) {
    }

    public function quoteIdentifier(string $name): string
    {
        if ($this->engine === PostmacloneConfig::ENGINE_POSTGRES) {
            return '"' . str_replace('"', '""', $name) . '"';
        }

        return '`' . str_replace('`', '``', $name) . '`';
    }

    public function quoteLiteral(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            if ($this->engine === PostmacloneConfig::ENGINE_POSTGRES) {
                return $value ? 'TRUE' : 'FALSE';
            }

            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $string = (string) $value;
        $escaped = str_replace("'", "''", $string);

        return "'" . $escaped . "'";
    }

    public function isPostgres(): bool
    {
        return $this->engine === PostmacloneConfig::ENGINE_POSTGRES;
    }
}
