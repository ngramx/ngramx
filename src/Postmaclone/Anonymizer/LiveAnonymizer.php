<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Anonymizer;

use Ngramx\Config\Schema\Postmaclone\ColumnRule;
use Ngramx\Config\Schema\Postmaclone\PostmacloneConfig;
use Ngramx\Config\Schema\Postmaclone\TableRule;
use Ngramx\Postmaclone\Exception\PostmacloneException;
use Ngramx\Postmaclone\FakerMethodResolver;
use Ngramx\Postmaclone\Progress\PercentReporter;
use PDO;
use Throwable;

class LiveAnonymizer
{
    /**
     * @var list<string>
     */
    private array $warnings = [];

    private readonly AnonymizedValueFactory $values;

    /**
     * @var (callable(string): void)|null
     */
    private $onProgress;

    /**
     * @param (callable(string): void)|null $onProgress
     */
    public function __construct(
        FakerMethodResolver $faker,
        private readonly SqlDialect $dialect,
        string $testPassword = PostmacloneConfig::DEFAULT_TEST_PASSWORD,
        private readonly int $chunkSize = 500,
        private readonly bool $strict = false,
        ?callable $onProgress = null,
        private readonly int $maxBoundBytes = 1_000_000,
    ) {
        $this->values = new AnonymizedValueFactory($faker, $testPassword);
        $this->onProgress = $onProgress;
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
            try {
                $this->anonymizeTable($pdo, $tableRule);
            } catch (Throwable $e) {
                $this->failOrWarn("Anonymization failed for table '{$tableRule->table}': {$e->getMessage()}");
            }
        }
    }

    private function anonymizeTable(PDO $pdo, TableRule $table): void
    {
        $pk = $table->primaryKey ?? $this->detectPrimaryKey($pdo, $table->table);
        if ($pk === null) {
            $this->failOrWarn("Table '{$table->table}' has no usable primary key; set tables.{$table->table}.primary_key");

            return;
        }

        if (!$this->tableExists($pdo, $table->table)) {
            $this->failOrWarn("Table '{$table->table}' does not exist; skipping");

            return;
        }

        $present = $this->existingColumns($pdo, $table->table);
        $columns = [];
        foreach ($table->columns as $column => $rule) {
            if (!isset($present[$column])) {
                $this->failOrWarn("Column '{$table->table}.{$column}' does not exist; skipping");
                continue;
            }
            $columns[$column] = $rule;
        }

        if ($columns === []) {
            return;
        }

        $selectCols = array_unique(array_merge([$pk], array_keys($columns)));
        $quoted = array_map(fn (string $c) => $this->dialect->quoteIdentifier($c), $selectCols);
        $from = ' FROM ' . $this->dialect->quoteIdentifier($table->table);
        $where = $this->tableWhere($columns);
        $sql = 'SELECT ' . implode(', ', $quoted) . $from . $where;

        $total = $this->countRows($pdo, $from . $where);
        if ($total !== null) {
            $this->progress(sprintf(
                'Anonymizing %s (%s rows)',
                $table->table,
                number_format($total)
            ));
        } else {
            $this->progress("Anonymizing {$table->table}");
        }

        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            $this->failOrWarn("Failed to select from {$table->table}; skipping");

            return;
        }

        $reporter = $this->onProgress !== null && $total !== null
            ? new PercentReporter($total, "Anonymizing {$table->table}", $this->onProgress)
            : null;

        $usable = new TableRule($table->table, $columns, $table->primaryKey);
        $batch = [];
        while (true) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                break;
            }
            /** @var array<string, mixed> $row */
            $batch[] = $row;
            if (count($batch) >= $this->chunkSize) {
                $this->applyBatch($pdo, $usable, $pk, $batch);
                $reporter?->add(count($batch));
                $batch = [];
            }
        }
        if ($batch !== []) {
            $this->applyBatch($pdo, $usable, $pk, $batch);
            $reporter?->add(count($batch));
        }
        $reporter?->finish();
    }

    /**
     * @param array<string, ColumnRule> $columns
     */
    private function tableWhere(array $columns): string
    {
        foreach ($columns as $rule) {
            if ($rule->where !== null && $rule->where !== '') {
                return ' WHERE ' . $rule->where;
            }
        }

        return '';
    }

    private function countRows(PDO $pdo, string $fromAndWhere): ?int
    {
        try {
            $stmt = $pdo->query('SELECT COUNT(*)' . $fromAndWhere);
            if ($stmt === false) {
                return null;
            }
            $n = $stmt->fetchColumn();

            return is_numeric($n) ? (int) $n : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function progress(string $message): void
    {
        if ($this->onProgress !== null) {
            ($this->onProgress)($message);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function applyBatch(PDO $pdo, TableRule $table, string $pk, array $rows): void
    {
        $prepared = [];
        foreach ($rows as $row) {
            if (!array_key_exists($pk, $row)) {
                $this->failOrWarn("Primary key '{$table->table}.{$pk}' missing; skipping row");
                continue;
            }
            $sets = $this->replacementsForRow($table, $row);
            if ($sets === []) {
                continue;
            }
            $prepared[] = ['pk' => $row[$pk], 'sets' => $sets];
        }

        foreach ($this->splitByBoundBudget($prepared) as $chunk) {
            try {
                $this->applyBatchedUpdate($pdo, $table, $pk, $chunk);
            } catch (Throwable $e) {
                $this->failOrWarn(
                    "Batched UPDATE failed for {$table->table}; falling back to per-row: {$e->getMessage()}"
                );
                foreach ($chunk as $item) {
                    $this->applySingleUpdate($pdo, $table, $pk, $item['pk'], $item['sets']);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function replacementsForRow(TableRule $table, array $row): array
    {
        $sets = [];
        foreach ($table->columns as $column => $rule) {
            if (!array_key_exists($column, $row)) {
                $this->failOrWarn("Column '{$table->table}.{$column}' missing; skipping column");
                continue;
            }
            $current = $row[$column];
            if ($current === null && $rule->preserveNulls) {
                continue;
            }

            try {
                $replacement = $this->values->value($rule, $current);
            } catch (Throwable $e) {
                $this->failOrWarn(
                    "Could not anonymize {$table->table}.{$column}: {$e->getMessage()}"
                );
                $replacement = $rule->isJsonRewrite() ? '{}' : null;
                if ($replacement === null) {
                    continue;
                }
            }

            $sets[$column] = $replacement;
        }

        return $sets;
    }

    /**
     * @param list<array{pk: mixed, sets: array<string, mixed>}> $prepared
     * @return list<list<array{pk: mixed, sets: array<string, mixed>}>>
     */
    private function splitByBoundBudget(array $prepared): array
    {
        $chunks = [];
        $current = [];
        $bytes = 0;
        foreach ($prepared as $item) {
            $rowBytes = $this->boundBytes($item['pk']);
            foreach ($item['sets'] as $value) {
                $rowBytes += $this->boundBytes($value);
            }
            if ($current !== [] && ($bytes + $rowBytes) > $this->maxBoundBytes) {
                $chunks[] = $current;
                $current = [];
                $bytes = 0;
            }
            $current[] = $item;
            $bytes += $rowBytes;
        }
        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private function boundBytes(mixed $value): int
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return 16;
        }

        return strlen((string) $value);
    }

    /**
     * @param list<array{pk: mixed, sets: array<string, mixed>}> $chunk
     */
    private function applyBatchedUpdate(PDO $pdo, TableRule $table, string $pk, array $chunk): void
    {
        if ($chunk === []) {
            return;
        }

        $quotedTable = $this->dialect->quoteIdentifier($table->table);
        $quotedPk = $this->dialect->quoteIdentifier($pk);
        $columns = [];
        foreach ($chunk as $item) {
            foreach (array_keys($item['sets']) as $column) {
                $columns[$column] = true;
            }
        }

        $params = [];
        $setSql = [];
        foreach (array_keys($columns) as $column) {
            $quotedCol = $this->dialect->quoteIdentifier($column);
            $token = preg_replace('/[^A-Za-z0-9_]/', '_', $column) ?? $column;
            $whens = [];
            foreach ($chunk as $i => $item) {
                if (!array_key_exists($column, $item['sets'])) {
                    continue;
                }
                $pkName = ':k' . $i . '_' . $token;
                $valName = ':v' . $i . '_' . $token;
                $whens[] = 'WHEN ' . $pkName . ' THEN ' . $valName;
                $params[$pkName] = $item['pk'];
                $params[$valName] = $item['sets'][$column];
            }
            if ($whens === []) {
                continue;
            }
            $setSql[] = $quotedCol . ' = CASE ' . $quotedPk . ' ' . implode(' ', $whens)
                . ' ELSE ' . $quotedCol . ' END';
        }

        if ($setSql === []) {
            return;
        }

        $in = [];
        foreach ($chunk as $i => $item) {
            $name = ':w' . $i;
            $in[] = $name;
            $params[$name] = $item['pk'];
        }

        $sql = 'UPDATE ' . $quotedTable
            . ' SET ' . implode(', ', $setSql)
            . ' WHERE ' . $quotedPk . ' IN (' . implode(', ', $in) . ')';

        $started = $pdo->beginTransaction();
        try {
            $update = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $update->bindValue($key, $value);
            }
            $update->execute();
            if ($started) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $sets
     */
    private function applySingleUpdate(PDO $pdo, TableRule $table, string $pk, mixed $pkValue, array $sets): void
    {
        if ($sets === []) {
            return;
        }

        $assignments = [];
        $params = [];
        $i = 0;
        foreach ($sets as $column => $value) {
            $placeholder = ':v' . $i;
            $assignments[] = $this->dialect->quoteIdentifier($column) . ' = ' . $placeholder;
            $params[$placeholder] = $value;
            $i++;
        }

        $sql = 'UPDATE ' . $this->dialect->quoteIdentifier($table->table)
            . ' SET ' . implode(', ', $assignments)
            . ' WHERE ' . $this->dialect->quoteIdentifier($pk) . ' = :pk';
        try {
            $update = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $update->bindValue($key, $value);
            }
            $update->bindValue(':pk', $pkValue);
            $update->execute();
        } catch (Throwable $e) {
            $this->failOrWarn(
                "UPDATE failed for {$table->table} {$pk}={$pkValue}: {$e->getMessage()}"
            );
        }
    }

    /**
     * @return array<string, true>
     */
    private function existingColumns(PDO $pdo, string $table): array
    {
        if ($this->dialect->isPostgres()) {
            $stmt = $pdo->prepare(
                'SELECT column_name FROM information_schema.columns '
                . 'WHERE table_schema = current_schema() AND table_name = :t'
            );
        } else {
            $stmt = $pdo->prepare(
                'SELECT COLUMN_NAME FROM information_schema.columns '
                . 'WHERE table_schema = DATABASE() AND table_name = :t'
            );
        }
        $stmt->execute([':t' => $table]);

        $present = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
            if (is_string($name) && $name !== '') {
                $present[$name] = true;
            }
        }

        return $present;
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
            } catch (Throwable) {
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

    private function failOrWarn(string $message): void
    {
        if ($this->strict) {
            throw new PostmacloneException($message);
        }

        $this->warnings[] = $message;
    }
}
