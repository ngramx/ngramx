<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Restore;

use Ngramx\Config\Schema\Postmaclone\PostmacloneConfig;
use Ngramx\Postmaclone\Exception\PostmacloneException;
use Ngramx\Postmaclone\Target\EphemeralTarget;

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
        private readonly MysqlRunner $mysql = new MysqlRunner(),
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
      AND c.relkind IN ('r', 'p')
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

  FOR r IN
    SELECT n.nspname, p.proname, pg_get_function_identity_arguments(p.oid) AS args
    FROM pg_proc p
    JOIN pg_namespace n ON n.oid = p.pronamespace
    WHERE n.nspname = 'public'
      AND p.proowner = (SELECT oid FROM pg_roles WHERE rolname = current_user)
  LOOP
    EXECUTE format('DROP FUNCTION IF EXISTS %I.%I(%s) CASCADE', r.nspname, r.proname, r.args);
  END LOOP;

  FOR r IN
    SELECT n.nspname, t.typname
    FROM pg_type t
    JOIN pg_namespace n ON n.oid = t.typnamespace
    WHERE n.nspname = 'public'
      AND t.typowner = (SELECT oid FROM pg_roles WHERE rolname = current_user)
      AND t.typtype IN ('e', 'c')
      AND t.typrelid = 0
  LOOP
    EXECUTE format('DROP TYPE IF EXISTS %I.%I CASCADE', r.nspname, r.typname);
  END LOOP;

  FOR r IN
    SELECT n.nspname, t.typname
    FROM pg_type t
    JOIN pg_namespace n ON n.oid = t.typnamespace
    WHERE n.nspname = 'public'
      AND t.typowner = (SELECT oid FROM pg_roles WHERE rolname = current_user)
      AND t.typtype = 'd'
  LOOP
    EXECUTE format('DROP DOMAIN IF EXISTS %I.%I CASCADE', r.nspname, r.typname);
  END LOOP;
END $$;
SQL;
    }

    private function wipeMysql(EphemeralTarget $target): void
    {
        $output = $this->mysql->capture($target, [
            '-N',
            '-e',
            'SET FOREIGN_KEY_CHECKS = 0; '
            . "SELECT CONCAT('DROP TABLE IF EXISTS `', table_name, '`;') "
            . 'FROM information_schema.tables '
            . "WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE';",
        ], 300);

        $drops = array_values(array_filter(array_map('trim', explode("\n", $output))));
        if ($drops === []) {
            return;
        }

        $batch = 'SET FOREIGN_KEY_CHECKS = 0; ' . implode(' ', $drops) . ' SET FOREIGN_KEY_CHECKS = 1;';
        $this->mysql->run($target, ['-e', $batch], null, 600);
    }
}
