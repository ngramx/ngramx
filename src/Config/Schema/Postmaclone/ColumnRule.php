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
 *
 * json / jsonArray rewrite a JSON document in place (decode → replace → encode)
 * instead of replacing the whole cell with a scalar faker value.
 */
readonly class ColumnRule
{
    /**
     * @param array<string, string> $json path or key => faker method
     */
    public function __construct(
        public string $column,
        public string $faker = '',
        public bool $unique = false,
        public bool $preserveNulls = true,
        public ?string $where = null,
        public array $json = [],
        public ?string $jsonArray = null,
        public bool $jsonRecursive = false,
        public bool $consistent = false,
    ) {
    }

    public function isJsonRewrite(): bool
    {
        return $this->json !== [] || $this->jsonArray !== null;
    }

    /**
     * @return list<string>
     */
    public function fakerExpressions(): array
    {
        $expressions = [];
        if ($this->faker !== '') {
            $expressions[] = $this->faker;
        }
        if ($this->jsonArray !== null && $this->jsonArray !== '') {
            $expressions[] = $this->jsonArray;
        }
        foreach ($this->json as $expression) {
            if ($expression !== '') {
                $expressions[] = $expression;
            }
        }

        return $expressions;
    }
}
