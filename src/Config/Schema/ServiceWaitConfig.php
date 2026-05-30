<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema;

readonly class ServiceWaitConfig
{
    public function __construct(
        public string $service,
        public int $timeout,
    ) {
    }
}
