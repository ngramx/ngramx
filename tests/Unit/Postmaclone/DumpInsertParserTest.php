<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Postmaclone\Anonymizer\DumpInsertParser;
use PHPUnit\Framework\TestCase;

class DumpInsertParserTest extends TestCase
{
    public function test_parses_quoted_insert_rows(): void
    {
        $sql = file_get_contents(dirname(__DIR__, 2) . '/fixtures/postmaclone/users.sql');
        $this->assertIsString($sql);

        $parser = new DumpInsertParser();
        $rows = $parser->parse($sql, ['users']);

        $this->assertCount(3, $rows['users']);
        $this->assertSame(1, $rows['users'][0]['id']);
        $this->assertSame('alice@example.com', $rows['users'][0]['email']);
        $this->assertNull($rows['users'][2]['email']);
        $this->assertSame('active', $rows['users'][0]['status']);
    }

    public function test_ignores_unlisted_tables(): void
    {
        $sql = 'INSERT INTO "orders" ("id", "total") VALUES (1, 10);';
        $parser = new DumpInsertParser();
        $rows = $parser->parse($sql, ['users']);
        $this->assertSame([], $rows['users']);
    }
}
