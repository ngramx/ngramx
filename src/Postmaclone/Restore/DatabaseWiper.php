<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Restore;

use Ngramx\Config\Schema\Postmaclone\PostmacloneConfig;
use Ngramx\Postmaclone\Exception\PostmacloneException;
use Ngramx\Postmaclone\Target\EphemeralTarget;
use Symfony\Component\Process\Process;

/**
 * Drop objects the connected role owns in the current database so the next restore starts clean.
 *
 * Does not DROP SCHEMA public — on DigitalOcean Managed Postgres that schema is owned by
 * doadmin, so scratch/anon roles get "must be owner of schema public".
 */
final class DatabaseWiper
{
    public function __construct(
        private readonly PsqlRunner $psql = new PsqlRunner(),
    ) {
    }

    public function wipe(string $engine, EphemeralTarget $target): void
    {
        if ($engine === PostmacloneConfig::ENGINE_POSTGRES) {
            $this->wipePostgres($target);

            return;
        }

        if (in_array($engine, [PostmacloneConfig::ENGINE_MYSQL, PostmacloneConfig::ENGINE_MARIADB], true)) {
            $this->wipeMysql($target);

            return;
        }

        throw new PostmacloneException("Database wipe is not supported for engine {$engine}");
    }

    private function wipePostgres(EphemeralTarget $target): void
    {
        $this->psql->runQuery($target, self::postgresOwnedObjectWipeSql(), 300);
    }

    /**
     * Only public objects owned by the current role. Never touches other databases or other owners.
     */
    public static function postgresOwnedObjectWipeSql(): string
    {
        return <<<'SQL'
DO $$
DECLARE r record;
BEGIN
  FOR r IN
    SELECT n.nspname, c.relname
    FROM pg_class c
    JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE n.nspname = 'public'
      AND c.relkind = 'r'
      AND c.relowner = (SELECT oid FROM pg_roles WHERE rolname = current_user)
  LOOP
    EXECUTE format('DROP TABLE IF EXISTS %I.%I CASCADE', r.nspname, r.relname);
  END LOOP;

  FOR r IN
    SELECT n.nspname, c.relname, c.relkind
    FROM pg_class c
    JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE n.nspname = 'public'
      AND c.relkind IN ('v', 'm')
      AND c.relowner = (SELECT oid FROM pg_roles WHERE rolname = current_user)
  LOOP
    IF r.relkind = 'm' THEN
      EXECUTE format('DROP MATERIALIZED VIEW IF EXISTS %I.%I CASCADE', r.nspname, r.relname);
    ELSE
      EXECUTE format('DROP VIEW IF EXISTS %I.%I CASCADE', r.nspname, r.relname);
    END IF;
  END LOOP;

  FOR r IN
    SELECT n.nspname, c.relname
    FROM pg_class c
    JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE n.nspname = 'public'
      AND c.relkind = 'S'
      AND c.relowner = (SELECT oid FROM pg_roles WHERE rolname = current_user)
  LOOP
    EXECUTE format('DROP SEQUENCE IF EXISTS %I.%I CASCADE', r.nspname, r.relname);
  END LOOP;
END $$;
SQL;
    }

    private function wipeMysql(EphemeralTarget $target): void
    {
        $process = new Process([
            'mysql',
            $target->databaseUrl,
            '-N',
            '-e',
            'SET FOREIGN_KEY_CHECKS = 0; '
            . "SELECT CONCAT('DROP TABLE IF EXISTS `', table_name, '`;') "
            . 'FROM information_schema.tables '
            . "WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE';",
        ]);
        $process->setTimeout(300);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new PostmacloneException('Failed to list MySQL tables for wipe: ' . $process->getErrorOutput());
        }

        $drops = array_filter(array_map('trim', explode("\n", $process->getOutput())));
        if ($drops === []) {
            return;
        }

        $batch = 'SET FOREIGN_KEY_CHECKS = 0; ' . implode(' ', $drops) . ' SET FOREIGN_KEY_CHECKS = 1;';
        $run = new Process(['mysql', $target->databaseUrl, '-e', $batch]);
        $run->setTimeout(600);
        $run->run();
        if (!$run->isSuccessful()) {
            throw new PostmacloneException('Failed to wipe MySQL database: ' . $run->getErrorOutput());
        }
    }
}
