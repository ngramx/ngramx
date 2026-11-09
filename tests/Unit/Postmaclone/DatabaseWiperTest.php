<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Postmaclone\Restore\DatabaseWiper;
use Ngramx\Postmaclone\Restore\MysqlRunner;
use Ngramx\Postmaclone\Target\EphemeralTarget;
use PHPUnit\Framework\TestCase;

final class DatabaseWiperTest extends TestCase
{
    public function test_mysql_wipe_has_no_process_timeout(): void
    {
        $seenTimeout = 'unset';
        $runner = $this->createMock(MysqlRunner::class);
        $runner->expects($this->once())
            ->method('capture')
            ->willReturn("DROP TABLE IF EXISTS `correspondence_packs`;\n");
        $runner->expects($this->once())
            ->method('run')
            ->willReturnCallback(function ($target, array $args, $stdin, $timeout) use (&$seenTimeout): void {
                $seenTimeout = $timeout;
                self::assertNull($stdin);
                self::assertSame(
                    [
                        '-e',
                        'SET FOREIGN_KEY_CHECKS = 0; DROP TABLE IF EXISTS `correspondence_packs`; SET FOREIGN_KEY_CHECKS = 1;',
                    ],
                    $args,
                );
            });

        (new DatabaseWiper(mysql: $runner))->wipe('mysql', $this->mysqlTarget());

        self::assertNull($seenTimeout);
    }

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

    private function mysqlTarget(): EphemeralTarget
    {
        return new EphemeralTarget(
            provider: 'remote',
            engine: 'mysql',
            host: 'db.example.com',
            port: 25060,
            database: 'hydra_prod_anon',
            username: 'postmaclone_scratch',
            password: 'secret',
            databaseUrl: 'mysql://postmaclone_scratch:secret@db.example.com:25060/hydra_prod_anon',
            expiresAt: '2026-11-09T16:00:00+00:00',
        );
    }
}
