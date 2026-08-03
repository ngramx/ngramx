<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema\Postmaclone;

readonly class FactoryConfig
{
    /**
     * @param array<string, FactoryDatasetConfig> $datasets keyed by dataset name
     */
    public function __construct(
        public string $version = '1',
        public array $datasets = [],
        public string $locale = PostmacloneConfig::DEFAULT_LOCALE,
        public ?int $seed = 42,
    ) {
    }
}
