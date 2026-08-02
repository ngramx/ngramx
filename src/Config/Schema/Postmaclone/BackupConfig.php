<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema\Postmaclone;

readonly class BackupConfig
{
    public const SOURCE_LOCAL = 'local';
    public const SOURCE_S3 = 's3';

    /**
     * @param list<string>|null $roles Postgres roles to stub before plain-SQL restore.
     */
    public function __construct(
        public string $source = self::SOURCE_LOCAL,
        public ?string $path = null,
        public ?string $region = null,
        public ?string $endpoint = null,
        public ?bool $pathStyle = null,
        /** Basename of the dump inside a dated daily folder (e.g. earl_kendrick_prod.sql.gz). */
        public ?string $file = null,
        /** 1Password op:// refs — safe to commit; resolved at runtime via `op read`. */
        public ?BackupCredentialsConfig $credentials = null,
        public ?array $roles = null,
    ) {
    }
}
