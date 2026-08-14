<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Anonymizer;

/**
 * Extracts row maps from plaintext SQL dump INSERT statements for configured tables.
 */
class DumpInsertParser
{
    /**
     * @param list<string> $tableNames
     * @return array<string, list<array<string, mixed>>> table => rows
     */
    public function parse(string $sql, array $tableNames): array
    {
        $wanted = array_fill_keys($tableNames, true);
        $result = [];
        foreach ($tableNames as $table) {
            $result[$table] = [];
        }

        $pattern = '/INSERT\s+INTO\s+(?:(?P<q1>"|`|\[)?(?P<table>[A-Za-z0-9_.]+)(?P<q2>"|`|\])?)\s*'
            . '(?:\((?P<cols>[^)]+)\)\s*)?'
            . 'VALUES\s*(?P<values>.+?);/is';

        if (preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER) === false) {
            return $result;
        }

        foreach ($matches as $match) {
            $table = $match['table'];
            // Strip schema prefix for matching (public.users -> users)
            $short = str_contains($table, '.') ? substr($table, (int) strrpos($table, '.') + 1) : $table;
            if (!isset($wanted[$table]) && !isset($wanted[$short])) {
                continue;
            }
            $key = isset($wanted[$table]) ? $table : $short;

            $columns = null;
            $cols = (string) $match['cols'];
            if ($cols !== '') {
                $columns = $this->parseColumnList($cols);
            }

            $valueGroups = $this->splitValueGroups($match['values']);
            foreach ($valueGroups as $group) {
                $values = $this->parseValueTuple($group);
                if ($columns === null) {
                    // Cannot map without column names
                    continue;
                }
                if (count($columns) !== count($values)) {
                    continue;
                }
                $row = [];
                foreach ($columns as $i => $column) {
                    $row[$column] = $values[$i];
                }
                $result[$key][] = $row;
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function parseColumnList(string $cols): array
    {
        $parts = preg_split('/\s*,\s*/', trim($cols)) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            $part = trim($part, '"`[]');
            if ($part !== '') {
                $out[] = $part;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function splitValueGroups(string $values): array
    {
        $values = trim($values);
        $groups = [];
        $depth = 0;
        $inString = false;
        $stringChar = '';
        $current = '';
        $len = strlen($values);

        for ($i = 0; $i < $len; $i++) {
            $ch = $values[$i];
            $prev = $i > 0 ? $values[$i - 1] : '';

            if ($inString) {
                $current .= $ch;
                if ($ch === $stringChar && $prev !== '\\') {
                    // handle doubled quotes ''
                    if ($stringChar === "'" && ($i + 1) < $len && $values[$i + 1] === "'") {
                        $current .= $values[++$i];
                        continue;
                    }
                    $inString = false;
                }
                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $inString = true;
                $stringChar = $ch;
                $current .= $ch;
                continue;
            }

            if ($ch === '(') {
                if ($depth === 0) {
                    $current = '';
                } else {
                    $current .= $ch;
                }
                $depth++;
                continue;
            }

            if ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    $groups[] = $current;
                    $current = '';
                } else {
                    $current .= $ch;
                }
                continue;
            }

            if ($depth > 0) {
                $current .= $ch;
            }
        }

        return $groups;
    }

    /**
     * @return list<mixed>
     */
    private function parseValueTuple(string $tuple): array
    {
        $values = [];
        $current = '';
        $inString = false;
        $stringChar = '';
        $len = strlen($tuple);

        for ($i = 0; $i < $len; $i++) {
            $ch = $tuple[$i];

            if ($inString) {
                if ($ch === $stringChar) {
                    if ($stringChar === "'" && ($i + 1) < $len && $tuple[$i + 1] === "'") {
                        $current .= "'";
                        $i++;
                        continue;
                    }
                    $inString = false;
                    continue;
                }
                $current .= $ch;
                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $inString = true;
                $stringChar = $ch;
                continue;
            }

            if ($ch === ',') {
                $values[] = $this->castToken(trim($current));
                $current = '';
                continue;
            }

            $current .= $ch;
        }

        if (trim($current) !== '' || $current === '0') {
            $values[] = $this->castToken(trim($current));
        } elseif ($tuple !== '' && str_ends_with(rtrim($tuple), ',')) {
            $values[] = null;
        }

        return $values;
    }

    private function castToken(string $token): mixed
    {
        if ($token === '' || strcasecmp($token, 'NULL') === 0) {
            return null;
        }

        if (strcasecmp($token, 'TRUE') === 0) {
            return true;
        }
        if (strcasecmp($token, 'FALSE') === 0) {
            return false;
        }

        if (preg_match('/^-?\d+$/', $token) === 1) {
            return (int) $token;
        }

        if (preg_match('/^-?\d+\.\d+$/', $token) === 1) {
            return (float) $token;
        }

        return $token;
    }
}
