<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema;

readonly class N8nConfig
{
    public function __construct(
        public string $workflowsDir,
    ) {
    }
}
