<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Restore;

use Ngramx\Postmaclone\Target\EphemeralTarget;

interface RestorerInterface
{
    public function restore(string $dumpPath, EphemeralTarget $target): void;
}
