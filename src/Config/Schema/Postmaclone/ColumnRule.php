<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema\Postmaclone;

/**
 * Opt-in anonymization rule for a single column.
 *
 * Only columns listed under postmaclone.tables.<table> are touched.
 * Unlisted columns are never read for rewriting and are left unchanged.
 *
 * preserveNulls controls NULL *cells* on an opted-in column: when true (default),
 * existing NULL values stay NULL instead of being filled with fake data.
 */
readonly class ColumnRule
{
    public function __construct(
        public string $column,
        public string $faker,
        public bool $unique = false,
        public bool $preserveNulls = true,
        public ?string $where = null,
    ) {
    }
}
