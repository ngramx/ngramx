<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Anonymizer;

use Ngramx\Config\Schema\Postmaclone\ColumnRule;
use Ngramx\Config\Schema\Postmaclone\PostmacloneConfig;
use Ngramx\Config\Schema\Postmaclone\TableRule;
use Ngramx\Postmaclone\Exception\PostmacloneException;
use Ngramx\Postmaclone\FakerMethodResolver;
use PDO;

class LiveAnonymizer
{
    /**
     * @var list<string>
     */
    private array $warnings = [];

    public function __construct(
        private readonly FakerMethodResolver $faker,
        private readonly SqlDialect $dialect,
        private readonly string $testPassword = PostmacloneConfig::DEFAULT_TEST_PASSWORD,
        private readonly int $chunkSize = 500,
        private readonly bool $strict = false,
    ) {
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @param array<string, TableRule> $tables
     */
    public function anonymize(PDO $pdo, array $tables): void
    {
        $this->warnings = [];
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        foreach ($tables as $tableRule) {
            $this->anonymizeTable($pdo, $tableRule);
        }
    }

    private function anonymizeTable(PDO $pdo, TableRule $table): void
    {
        $pk = $table->primaryKey ?? $this->detectPrimaryKey($pdo, $table->table);
        if ($pk === null) {
            $message = "Table '{$table->table}' has no usable primary key; set tables.{$table->table}.primary_key";
            if ($this->strict) {
                throw new PostmacloneException($message);
            }
            $this->warnings[] = $message;

            return;
        }

        if (!$this->tableExists($pdo, $table->table)) {
            $message = "Table '{$table->table}' does not exist; skipping";
            if ($this->strict) {
                throw new PostmacloneException($message);
            }
            $this->warnings[] = $message;

            return;
        }

        $columns = array_keys($table->columns);
        $selectCols = array_unique(array_merge([$pk], $columns));
        $quoted = array_map(fn (string $c) => $this->dialect->quoteIdentifier($c), $selectCols);
        $sql = 'SELECT ' . implode(', ', $quoted)
            . ' FROM ' . $this->dialect->quoteIdentifier($table->table);

        // Optional per-column where is OR'd lightly; first non-null where wins as filter.
        foreach ($table->columns as $rule) {
            if ($rule->where !== null && $rule->where !== '') {
                $sql .= ' WHERE ' . $rule->where;
                break;
            }
        }

        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            throw new PostmacloneException("Failed to select from {$table->table}");
        }

        $batch = [];
        while (true) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                break;
            }
            /** @var array<string, mixed> $row */
            $batch[] = $row;
            if (count($batch) >= $this->chunkSize) {
                $this->applyBatch($pdo, $table, $pk, $batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $this->applyBatch($pdo, $table, $pk, $batch);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function applyBatch(PDO $pdo, TableRule $table, string $pk, array $rows): void
    {
        foreach ($rows as $row) {
            $sets = [];
            $params = [];
            $i = 0;
            foreach ($table->columns as $column => $rule) {
                if (!array_key_exists($column, $row)) {
                    $this->warnings[] = "Column '{$table->table}.{$column}' missing; skipping column";
                    continue;
                }
                $current = $row[$column];
                // Opt-in column: leave existing NULL cells alone unless preserve_nulls: false
                if ($current === null && $rule->preserveNulls) {
                    continue;
                }

                $placeholder = ':v' . $i;
                $sets[] = $this->dialect->quoteIdentifier($column) . ' = ' . $placeholder;
                $params[$placeholder] = $this->fakeValue($rule);
                $i++;
            }

            if ($sets === []) {
                continue;
            }

            $sql = 'UPDATE ' . $this->dialect->quoteIdentifier($table->table)
                . ' SET ' . implode(', ', $sets)
                . ' WHERE ' . $this->dialect->quoteIdentifier($pk) . ' = :pk';
            $update = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $update->bindValue($key, $value);
            }
            $update->bindValue(':pk', $row[$pk]);
            $update->execute();
        }
    }

    private function fakeValue(ColumnRule $rule): mixed
    {
        if ($rule->faker === 'password') {
            return password_hash($this->testPassword, PASSWORD_BCRYPT);
        }

        return $this->faker->generate($rule->faker, $rule->unique);
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        if ($this->dialect->isPostgres()) {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = :t'
            );
        } else {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t'
            );
        }
        $stmt->execute([':t' => $table]);

        return (bool) $stmt->fetchColumn();
    }

    private function detectPrimaryKey(PDO $pdo, string $table): ?string
    {
        if ($this->dialect->isPostgres()) {
            $sql = <<<'SQL'
SELECT a.attname
FROM pg_index i
JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
WHERE i.indrelid = :table::regclass AND i.indisprimary
LIMIT 1
SQL;
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':table' => $table]);
                $name = $stmt->fetchColumn();

                return is_string($name) && $name !== '' ? $name : null;
            } catch (\Throwable) {
                return 'id';
            }
        }

        $sql = <<<'SQL'
SELECT COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = :table
  AND CONSTRAINT_NAME = 'PRIMARY'
LIMIT 1
SQL;
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':table' => $table]);
        $name = $stmt->fetchColumn();

        return is_string($name) && $name !== '' ? $name : null;
    }
}
