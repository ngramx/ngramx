<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Config\Schema\Postmaclone\ColumnRule;
use Ngramx\Config\Schema\Postmaclone\TableRule;
use Ngramx\Postmaclone\Anonymizer\LiveAnonymizer;
use Ngramx\Postmaclone\Anonymizer\SqlDialect;
use Ngramx\Postmaclone\Exception\PostmacloneException;
use Ngramx\Postmaclone\FakerMethodResolver;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

class LiveAnonymizerTest extends TestCase
{
    public function test_batch_issues_one_update_for_chunk(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec('CREATE TABLE people (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');
        $pdo->exec("INSERT INTO people (id, name, email) VALUES (1, 'Ann', 'ann@example.com')");
        $pdo->exec("INSERT INTO people (id, name, email) VALUES (2, 'Bob', 'bob@example.com')");
        $pdo->exec("INSERT INTO people (id, name, email) VALUES (3, 'Cam', 'cam@example.com')");

        $anonymizer = $this->anonymizer($pdo);
        $anonymizer->anonymize($pdo, [
            'people' => new TableRule('people', [
                'name' => new ColumnRule('name', 'clear'),
                'email' => new ColumnRule('email', 'clear'),
            ], 'id'),
        ]);

        $this->assertSame(1, $pdo->updateCount);
        $selected = $pdo->query('SELECT name, email FROM people ORDER BY id');
        $this->assertNotFalse($selected);
        $rows = $selected->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame(['', ''], [$rows[0]['name'], $rows[0]['email']]);
        $this->assertSame(['', ''], [$rows[1]['name'], $rows[1]['email']]);
        $this->assertSame(['', ''], [$rows[2]['name'], $rows[2]['email']]);
    }

    public function test_preserve_nulls_does_not_null_other_rows_in_the_batch(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec('CREATE TABLE people (id INTEGER PRIMARY KEY, email TEXT)');
        $pdo->exec("INSERT INTO people (id, email) VALUES (1, 'ann@example.com')");
        $pdo->exec('INSERT INTO people (id, email) VALUES (2, NULL)');
        $pdo->exec("INSERT INTO people (id, email) VALUES (3, 'cam@example.com')");

        $anonymizer = $this->anonymizer($pdo);
        $anonymizer->anonymize($pdo, [
            'people' => new TableRule('people', [
                'email' => new ColumnRule('email', 'clear', preserveNulls: true),
            ], 'id'),
        ]);

        $this->assertSame(1, $pdo->updateCount);
        $selected = $pdo->query('SELECT email FROM people ORDER BY id');
        $this->assertNotFalse($selected);
        $emails = $selected->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['', null, ''], $emails);
    }

    public function test_json_rewrite_updates_in_one_statement(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY, payload TEXT)');
        $pdo->exec("INSERT INTO invoices (id, payload) VALUES (1, '{\"email\":\"a@example.com\",\"keep\":1}')");
        $pdo->exec("INSERT INTO invoices (id, payload) VALUES (2, '{\"email\":\"b@example.com\",\"keep\":2}')");

        $anonymizer = $this->anonymizer($pdo);
        $anonymizer->anonymize($pdo, [
            'invoices' => new TableRule('invoices', [
                'payload' => new ColumnRule('payload', json: ['email' => 'safeEmail'], jsonRecursive: true),
            ], 'id'),
        ]);

        $this->assertSame(1, $pdo->updateCount);
        $selected = $pdo->query('SELECT payload FROM invoices ORDER BY id');
        $this->assertNotFalse($selected);
        $payloads = $selected->fetchAll(PDO::FETCH_COLUMN);
        $first = json_decode((string) $payloads[0], true);
        $second = json_decode((string) $payloads[1], true);
        $this->assertIsArray($first);
        $this->assertIsArray($second);
        $this->assertSame(1, $first['keep']);
        $this->assertSame(2, $second['keep']);
        $this->assertNotSame('a@example.com', $first['email']);
        $this->assertNotSame('b@example.com', $second['email']);
        $this->assertStringContainsString('@', (string) $first['email']);
    }

    public function test_oversized_payload_splits_into_multiple_updates(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT)');
        $pdo->exec("INSERT INTO notes (id, body) VALUES (1, 'aaaaaaaaaa')");
        $pdo->exec("INSERT INTO notes (id, body) VALUES (2, 'bbbbbbbbbb')");
        $pdo->exec("INSERT INTO notes (id, body) VALUES (3, 'cccccccccc')");

        $anonymizer = $this->anonymizer($pdo, maxBoundBytes: 24);
        $anonymizer->anonymize($pdo, [
            'notes' => new TableRule('notes', [
                'body' => new ColumnRule('body', 'clear'),
            ], 'id'),
        ]);

        $this->assertGreaterThan(1, $pdo->updateCount);
        $selected = $pdo->query('SELECT body FROM notes ORDER BY id');
        $this->assertNotFalse($selected);
        $bodies = $selected->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['', '', ''], $bodies);
    }

    public function test_batch_failure_falls_back_to_per_row_updates(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec('CREATE TABLE people (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO people (id, name) VALUES (1, 'Ann')");
        $pdo->exec("INSERT INTO people (id, name) VALUES (2, 'Bob')");

        $pdo->failNextBatch = true;
        $anonymizer = $this->anonymizer($pdo);
        $anonymizer->anonymize($pdo, [
            'people' => new TableRule('people', [
                'name' => new ColumnRule('name', 'clear'),
            ], 'id'),
        ]);

        $this->assertGreaterThanOrEqual(3, $pdo->updateCount);
        $selected = $pdo->query('SELECT name FROM people ORDER BY id');
        $this->assertNotFalse($selected);
        $names = $selected->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['', ''], $names);
        $this->assertNotSame([], $anonymizer->warnings());
    }

    public function test_selects_are_paged_by_primary_key(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec('CREATE TABLE people (id INTEGER PRIMARY KEY, name TEXT)');
        for ($i = 1; $i <= 5; $i++) {
            $pdo->exec("INSERT INTO people (id, name) VALUES ({$i}, 'n{$i}')");
        }

        $anonymizer = $this->anonymizer($pdo, chunkSize: 2);
        $anonymizer->anonymize($pdo, [
            'people' => new TableRule('people', [
                'name' => new ColumnRule('name', 'clear'),
            ], 'id'),
        ]);

        $pageSelects = array_values(array_filter(
            $pdo->queries,
            static fn (string $sql): bool => str_contains($sql, 'FROM "people"')
                && str_contains($sql, 'LIMIT 2')
        ));
        $this->assertCount(3, $pageSelects);
        $this->assertStringNotContainsString(':last_pk', $pageSelects[0]);
        $this->assertStringContainsString('"id" > :last_pk', $pageSelects[1]);
        $this->assertStringContainsString('"id" > :last_pk', $pageSelects[2]);
        $this->assertSame(3, $pdo->updateCount);

        $selected = $pdo->query('SELECT name FROM people ORDER BY id');
        $this->assertNotFalse($selected);
        $this->assertSame(['', '', '', '', ''], $selected->fetchAll(PDO::FETCH_COLUMN));
    }

    public function test_keyset_parentheses_keep_or_where_from_repeating_the_first_page(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec('CREATE TABLE people (id INTEGER PRIMARY KEY, name TEXT, status TEXT)');
        for ($i = 1; $i <= 5; $i++) {
            $pdo->exec("INSERT INTO people (id, name, status) VALUES ({$i}, 'n{$i}', 'a')");
        }

        $anonymizer = $this->anonymizer($pdo, chunkSize: 2);
        $anonymizer->anonymize($pdo, [
            'people' => new TableRule('people', [
                'name' => new ColumnRule('name', 'clear', where: "status = 'a' OR status = 'b'"),
            ], 'id'),
        ]);

        $keysetSelects = array_values(array_filter(
            $pdo->queries,
            static fn (string $sql): bool => str_contains($sql, ':last_pk')
        ));
        $this->assertNotSame([], $keysetSelects);
        $this->assertStringContainsString(
            'WHERE (status = \'a\' OR status = \'b\') AND "id" > :last_pk',
            $keysetSelects[0]
        );
        $this->assertSame(3, $pdo->updateCount);

        $selected = $pdo->query('SELECT name FROM people ORDER BY id');
        $this->assertNotFalse($selected);
        $this->assertSame(['', '', '', '', ''], $selected->fetchAll(PDO::FETCH_COLUMN));
    }

    public function test_strict_batch_failure_falls_back_then_throws(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec('CREATE TABLE people (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO people (id, name) VALUES (1, 'Ann')");
        $pdo->exec("INSERT INTO people (id, name) VALUES (2, 'Bob')");

        $pdo->failNextBatch = true;
        $anonymizer = $this->anonymizer($pdo, strict: true);

        try {
            $anonymizer->anonymize($pdo, [
                'people' => new TableRule('people', [
                    'name' => new ColumnRule('name', 'clear'),
                ], 'id'),
            ]);
            $this->fail('Expected PostmacloneException in strict mode');
        } catch (PostmacloneException $e) {
            $this->assertStringContainsString('fell back to per-row', $e->getMessage());
        }

        $selected = $pdo->query('SELECT name FROM people ORDER BY id');
        $this->assertNotFalse($selected);
        $names = $selected->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['', ''], $names);
    }

    public function test_row_update_failure_retries_remaining_columns(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec('CREATE TABLE people (id INTEGER PRIMARY KEY, email TEXT, token INTEGER NOT NULL)');
        $pdo->exec("INSERT INTO people (id, email, token) VALUES (1, 'ann@example.com', 99)");

        $pdo->failMultiColumnUpdates = true;
        $anonymizer = $this->anonymizer($pdo);
        $anonymizer->anonymize($pdo, [
            'people' => new TableRule('people', [
                'email' => new ColumnRule('email', 'clear'),
                'token' => new ColumnRule('token', 'clear'),
            ], 'id'),
        ]);

        $selected = $pdo->query('SELECT email, token FROM people WHERE id = 1');
        $this->assertNotFalse($selected);
        $row = $selected->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame('', $row['email']);
        $this->assertSame(0, (int) $row['token']);
        $this->assertNotSame([], $anonymizer->warnings());
    }

    public function test_empty_table_deletes_all_rows(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec('CREATE TABLE history (id INTEGER PRIMARY KEY, email TEXT)');
        $pdo->exec("INSERT INTO history (id, email) VALUES (1, 'ann@example.com')");
        $pdo->exec("INSERT INTO history (id, email) VALUES (2, 'bob@example.com')");

        $anonymizer = $this->anonymizer($pdo);
        $anonymizer->anonymize($pdo, [
            'history' => new TableRule('history', [], empty: true),
        ]);

        $count = $pdo->query('SELECT COUNT(*) FROM history');
        $this->assertNotFalse($count);
        $this->assertSame(0, (int) $count->fetchColumn());
    }

    private function anonymizer(
        CountingPdo $pdo,
        int $maxBoundBytes = 1_000_000,
        bool $strict = false,
        int $chunkSize = 50,
    ): LiveAnonymizer {
        return new LiveAnonymizer(
            new FakerMethodResolver('en_GB', 42),
            new SqlDialect('postgres'),
            chunkSize: $chunkSize,
            strict: $strict,
            maxBoundBytes: $maxBoundBytes,
        );
    }

    private function sqlite(): CountingPdo
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required for LiveAnonymizer tests');
        }

        $pdo = new CountingPdo('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }
}

final class CountingPdo extends PDO
{
    public int $updateCount = 0;

    public bool $failNextBatch = false;

    public bool $failMultiColumnUpdates = false;

    /** @var list<string> */
    public array $queries = [];

    /**
     * @param array<int, mixed> $options
     */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries[] = $query;
        if (str_contains($query, 'information_schema.tables')) {
            $query = "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :t LIMIT 1";
        } elseif (str_contains($query, 'information_schema.columns')) {
            $query = 'SELECT name FROM pragma_table_info(:t)';
        } elseif (preg_match('/^\s*UPDATE\b/i', $query) === 1) {
            ++$this->updateCount;
            if ($this->failNextBatch && str_contains($query, 'CASE')) {
                $this->failNextBatch = false;
                throw new \RuntimeException('forced batch failure');
            }
            if ($this->failMultiColumnUpdates && preg_match('/SET\s+.+,/is', $query) === 1) {
                throw new \RuntimeException('forced multi-column failure');
            }
        }

        return parent::prepare($query, $options);
    }
}
