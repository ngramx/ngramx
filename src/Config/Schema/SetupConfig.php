<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema;

readonly class SetupConfig
{
    /**
     * @param CommandDefinition[] $preStart
     * @param CommandDefinition[] $initialize
     */
    public function __construct(
        public array $preStart = [],
        public array $initialize = [],
    ) {
    }
}
