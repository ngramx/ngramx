<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Target;

use Ngramx\Postmaclone\PostmacloneLockData;

interface EphemeralTargetInterface
{
    public function provision(string $engine, int $ttlHours): EphemeralTarget;

    public function destroy(PostmacloneLockData $lock): void;
}
