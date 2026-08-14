<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Restore;

use Ngramx\Postmaclone\Exception\PostmacloneException;
use Ngramx\Postmaclone\Target\EphemeralTarget;
use Symfony\Component\Process\Process;

class MysqlRestorer implements RestorerInterface
{
    public function restore(string $dumpPath, EphemeralTarget $target): void
    {
        if (!is_file($dumpPath)) {
            throw new PostmacloneException("Dump not found: {$dumpPath}");
        }

        $cmd = [
            'mysql',
            '-h', $target->host,
            '-P', (string) $target->port,
            '-u', $target->username,
            $target->database,
        ];

        $process = new Process($cmd);
        $process->setInput(file_get_contents($dumpPath) ?: '');
        $process->setEnv(array_merge($_ENV, ['MYSQL_PWD' => $target->password]));
        $process->setTimeout(3600);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new PostmacloneException('mysql restore failed: ' . $process->getErrorOutput());
        }
    }
}
