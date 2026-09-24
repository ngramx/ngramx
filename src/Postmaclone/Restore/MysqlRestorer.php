<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Restore;

use Ngramx\Postmaclone\Exception\PostmacloneException;
use Ngramx\Postmaclone\Target\EphemeralTarget;

class MysqlRestorer implements RestorerInterface
{
    public function __construct(
        private readonly MysqlRunner $mysql = new MysqlRunner(),
    ) {
    }

    public function restore(string $dumpPath, EphemeralTarget $target): void
    {
        if (!is_file($dumpPath)) {
            throw new PostmacloneException("Dump not found: {$dumpPath}");
        }

        try {
            $this->mysql->runFile($target, $dumpPath, 3600);
        } catch (PostmacloneException $e) {
            throw new PostmacloneException(
                'mysql restore failed: ' . $e->getMessage()
                . "\nTip: ngramx postmaclone doctor",
                0,
                $e
            );
        }
    }
}
