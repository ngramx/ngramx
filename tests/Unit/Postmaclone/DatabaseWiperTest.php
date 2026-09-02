<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Postmaclone\Restore\DatabaseWiper;
use PHPUnit\Framework\TestCase;

final class DatabaseWiperTest extends TestCase
{
    public function test_postgres_wipe_drops_owned_public_objects_not_schema(): void
    {
        $sql = DatabaseWiper::postgresOwnedObjectWipeSql();

        self::assertStringNotContainsString('DROP SCHEMA', $sql);
        self::assertStringNotContainsString('CREATE SCHEMA', $sql);
        self::assertStringContainsString('DROP TABLE IF EXISTS', $sql);
        self::assertStringContainsString('DROP FUNCTION IF EXISTS', $sql);
        self::assertStringContainsString('DROP PROCEDURE IF EXISTS', $sql);
        self::assertStringContainsString('DROP AGGREGATE IF EXISTS', $sql);
        self::assertStringContainsString('DROP TYPE IF EXISTS', $sql);
        self::assertStringContainsString('DROP DOMAIN IF EXISTS', $sql);
        self::assertStringContainsString("c.relkind IN ('r', 'p')", $sql);
        self::assertStringContainsString("t.typtype = 'c' AND c.relkind = 'c'", $sql);
        self::assertStringNotContainsString('typrelid = 0', $sql);
        self::assertStringContainsString('c.relowner = (SELECT oid FROM pg_roles WHERE rolname = current_user)', $sql);
        self::assertStringContainsString("n.nspname = 'public'", $sql);
    }
}
