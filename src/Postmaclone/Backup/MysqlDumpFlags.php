<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Backup;

/**
 * mysqldump flags safe for restricted / read-only source users (no PROCESS / LOCK TABLES).
 */
final class MysqlDumpFlags
{
    /**
     * Flags for dumping a live source that may only grant SELECT (+ SHOW VIEW).
     *
     * @return list<string>
     */
    public static function forRestrictedSource(): array
    {
        $flags = [
            '--single-transaction',
            '--quick',
            '--no-tablespaces',
        ];

        // Optional client-specific flags — omit when the installed mysqldump lacks them.
        foreach ([
            '--column-statistics=0' => 'column-statistics',
            '--set-gtid-purged=OFF' => 'set-gtid-purged',
        ] as $flag => $helpToken) {
            if (self::helpContains($helpToken)) {
                $flags[] = $flag;
            }
        }

        return $flags;
    }

    /**
     * Extra flags when dumping a scratch/clone DB where the login has full privileges.
     *
     * @return list<string>
     */
    public static function forScratchDatabase(): array
    {
        return array_merge(self::forRestrictedSource(), [
            '--routines',
            '--triggers',
        ]);
    }

    private static function helpContains(string $token): bool
    {
        static $help = null;
        if ($help === null) {
            $help = shell_exec('mysqldump --help 2>/dev/null') ?? '';
        }

        return str_contains($help, $token);
    }
}
