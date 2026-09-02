<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema\Postmaclone;

readonly class FactoryDatasetConfig
{
    /**
     * @param array<string, TableRule> $tables
     * @param list<string>|null $includeTables
     * @param list<string>|null $excludeTables
     */
    public function __construct(
        public string $name,
        public ?string $engine = null,
        public string $locale = PostmacloneConfig::DEFAULT_LOCALE,
        public ?int $seed = 42,
        public BackupConfig $backup = new BackupConfig(),
        public PublishConfig $publish = new PublishConfig(),
        public TargetConfig $target = new TargetConfig(provider: TargetConfig::PROVIDER_DOCKER),
        public ?SharedDbConfig $shared = null,
        public array $tables = [],
        public ?array $includeTables = null,
        public ?array $excludeTables = null,
        public string $testPassword = PostmacloneConfig::DEFAULT_TEST_PASSWORD,
    ) {
    }
}
