<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema\Postmaclone;

readonly class TableRule
{
    /**
     * @param array<string, ColumnRule> $columns keyed by column name
     */
    public function __construct(
        public string $table,
        public array $columns,
        public ?string $primaryKey = null,
    ) {
    }
}
