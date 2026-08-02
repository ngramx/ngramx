<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Config\Schema\Postmaclone\ColumnRule;
use Ngramx\Config\Schema\Postmaclone\TableRule;
use Ngramx\Postmaclone\Anonymizer\SqlDialect;
use Ngramx\Postmaclone\Anonymizer\SqlEmitter;
use Ngramx\Postmaclone\FakerMethodResolver;
use PHPUnit\Framework\TestCase;

class SqlEmitterTest extends TestCase
{
    public function test_only_opt_in_columns_appear_in_updates(): void
    {
        $tables = [
            'users' => new TableRule(
                table: 'users',
                columns: [
                    'email' => new ColumnRule('email', 'safeEmail'),
                    'first_name' => new ColumnRule('first_name', 'firstName'),
                ],
                primaryKey: 'id',
            ),
        ];

        $rows = [
            'users' => [
                [
                    'id' => 1,
                    'email' => 'alice@example.com',
                    'first_name' => 'Alice',
                    'last_name' => 'Smith',
                    'status' => 'active',
                ],
            ],
        ];

        $emitter = new SqlEmitter(
            new FakerMethodResolver('en_GB', 42),
            new SqlDialect('postgres'),
        );
        $sql = $emitter->emit($tables, $rows);

        $this->assertStringContainsString('UPDATE "users" SET', $sql);
        $this->assertStringContainsString('"email"', $sql);
        $this->assertStringContainsString('"first_name"', $sql);
        $this->assertStringNotContainsString('"last_name"', $sql);
        $this->assertStringNotContainsString('"status"', $sql);
        $this->assertStringContainsString('WHERE "id" = 1', $sql);
        // Fake values should differ from originals (seeded faker)
        $this->assertStringNotContainsString('alice@example.com', $sql);
        $this->assertStringNotContainsString("'Alice'", $sql);
    }

    public function test_preserves_null_cells_on_opt_in_columns_by_default(): void
    {
        $tables = [
            'users' => new TableRule(
                table: 'users',
                columns: [
                    'email' => new ColumnRule('email', 'safeEmail', preserveNulls: true),
                ],
                primaryKey: 'id',
            ),
        ];
        $rows = [
            'users' => [
                ['id' => 3, 'email' => null],
            ],
        ];

        $emitter = new SqlEmitter(
            new FakerMethodResolver('en_GB', 42),
            new SqlDialect('postgres'),
        );
        $sql = $emitter->emit($tables, $rows);

        // No UPDATE for the null-only row when preserve_nulls is true
        $this->assertStringNotContainsString('WHERE "id" = 3', $sql);
    }

    public function test_mysql_quoting(): void
    {
        $tables = [
            'users' => new TableRule(
                table: 'users',
                columns: ['email' => new ColumnRule('email', 'safeEmail')],
                primaryKey: 'id',
            ),
        ];
        $rows = ['users' => [['id' => 1, 'email' => 'a@b.com']]];

        $emitter = new SqlEmitter(
            new FakerMethodResolver('en_US', 1),
            new SqlDialect('mysql'),
        );
        $sql = $emitter->emit($tables, $rows);
        $this->assertStringContainsString('UPDATE `users` SET', $sql);
        $this->assertStringContainsString('`email`', $sql);
    }
}
