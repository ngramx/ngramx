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
        foreach ($rows as $row) {
            $sets = [];
            $params = [];
            $i = 0;
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

                $placeholder = ':v' . $i;
                $sets[] = $this->dialect->quoteIdentifier($column) . ' = ' . $placeholder;
                $params[$placeholder] = $replacement;
                $i++;
            }

            if ($sets === []) {
                continue;
            }

            $sql = 'UPDATE ' . $this->dialect->quoteIdentifier($table->table)
                . ' SET ' . implode(', ', $sets)
                . ' WHERE ' . $this->dialect->quoteIdentifier($pk) . ' = :pk';
            try {
                $update = $pdo->prepare($sql);
                foreach ($params as $key => $value) {
                    $update->bindValue($key, $value);
                }
                $update->bindValue(':pk', $row[$pk]);
                $update->execute();
            } catch (Throwable $e) {
                $this->failOrWarn(
                    "UPDATE failed for {$table->table} {$pk}={$row[$pk]}: {$e->getMessage()}"
                );
            }
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
