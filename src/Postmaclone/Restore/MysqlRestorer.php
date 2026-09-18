<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Restore;

use Ngramx\Postmaclone\Exception\PostmacloneException;
use Ngramx\Postmaclone\Target\EphemeralTarget;

class MysqlRestorer implements RestorerInterface
{
    /** Hydra-sized dumps into managed MySQL regularly exceed one hour. */
    public const RESTORE_TIMEOUT_SECONDS = 10800;

    public function __construct(
        private readonly MysqlRunner $mysql = new MysqlRunner(),
        private readonly MysqlDumpSanitizer $sanitizer = new MysqlDumpSanitizer(),
    ) {
    }

    public function restore(string $dumpPath, EphemeralTarget $target): void
    {
        if (!is_file($dumpPath)) {
            throw new PostmacloneException("Dump not found: {$dumpPath}");
        }

        $in = fopen($dumpPath, 'rb');
        if ($in === false) {
            throw new PostmacloneException("Failed to open dump: {$dumpPath}");
        }

        try {
            $this->sanitizer->appendFilter($in);
            $this->mysql->run($target, [], $in, self::RESTORE_TIMEOUT_SECONDS);
        } catch (PostmacloneException $e) {
            throw new PostmacloneException(
                'mysql restore failed: ' . $e->getMessage()
                . "\nTip: ngramx postmaclone doctor",
                0,
                $e
            );
        } finally {
            fclose($in);
        }
    }
}
