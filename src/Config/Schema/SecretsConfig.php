<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema;

readonly class SecretsConfig
{
    /**
     * @param string[] $required
     */
    public function __construct(
        public string $provider = 'env',
        public array $required = [],
    ) {
    }
}
