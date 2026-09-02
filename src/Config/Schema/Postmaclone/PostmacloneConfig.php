<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema\Postmaclone;

readonly class PostmacloneConfig
{
    public const ENGINE_POSTGRES = 'postgres';
    public const ENGINE_MYSQL = 'mysql';
    public const ENGINE_MARIADB = 'mariadb';

    public const DEFAULT_LOCALE = 'en_GB';
    public const DEFAULT_TEST_PASSWORD = 'password';

    /**
     * @param array<string, TableRule> $tables keyed by table name
     * @param list<string> $denyHosts
     * @param array<string, EngineConnectionsConfig> $engines keyed by engine name
     */
    public function __construct(
        public ?string $engine = null,
        public string $locale = self::DEFAULT_LOCALE,
        public ?int $seed = 42,
        public BackupConfig $backup = new BackupConfig(),
        public ?PrebuiltConfig $prebuilt = null,
        public ?SharedDbConfig $shared = null,
        public TargetConfig $target = new TargetConfig(),
        public array $tables = [],
        public string $testPassword = self::DEFAULT_TEST_PASSWORD,
        public array $denyHosts = [],
        public array $engines = [],
    ) {
    }

    public function hasPrebuilt(): bool
    {
        return $this->prebuilt !== null
            && $this->prebuilt->path !== null
            && $this->prebuilt->path !== '';
    }

    public function hasShared(): bool
    {
        return $this->shared !== null && $this->shared->isConfigured();
    }
}
