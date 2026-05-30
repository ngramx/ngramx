<?php

declare(strict_types=1);

namespace Ngramx\Laravel;

class LaravelLogParser
{
    private const ENTRY_PATTERN = '/^\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}:\d{2})?)\]\s+\S+\.(\w+):\s+(.*)$/';

    /**
     * Parse raw Laravel log output into structured entries.
     *
     * @return LogEntry[]
     */
    public function parse(string $rawLog): array
    {
        if (trim($rawLog) === '') {
            return [];
        }

        $lines = explode("\n", $rawLog);
        $entries = [];
        $currentTimestamp = null;
        $currentLevel = '';
        $currentMessage = '';
        $traceLines = [];

        foreach ($lines as $line) {
            if (preg_match(self::ENTRY_PATTERN, $line, $matches)) {
                if ($currentTimestamp !== null) {
                    $entries[] = $this->buildEntry($currentTimestamp, $currentLevel, $currentMessage, $traceLines);
                }

                $currentTimestamp = $matches[1];
                $currentLevel = strtoupper($matches[2]);
                $currentMessage = $matches[3];
                $traceLines = [];
            } elseif ($currentTimestamp !== null) {
                $traceLines[] = $line;
            }
        }

        if ($currentTimestamp !== null) {
            $entries[] = $this->buildEntry($currentTimestamp, $currentLevel, $currentMessage, $traceLines);
        }

        return $entries;
    }

    /**
     * Group and deduplicate entries into a summary, sorted by count descending.
     *
     * @param LogEntry[] $entries
     * @return LogSummaryEntry[]
     */
    public function summarise(array $entries): array
    {
        $groups = [];

        foreach ($entries as $entry) {
            $key = $entry->level . '|' . $this->normaliseMessage($entry->message);

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'level' => $entry->level,
                    'message' => $this->normaliseMessage($entry->message),
                    'count' => 0,
                    'lastOccurrence' => $entry->timestamp,
                ];
            }

            $groups[$key]['count']++;

            if ($entry->timestamp > $groups[$key]['lastOccurrence']) {
                $groups[$key]['lastOccurrence'] = $entry->timestamp;
            }
        }

        usort($groups, fn (array $a, array $b) => $b['count'] <=> $a['count']);

        return array_map(
            fn (array $g) => new LogSummaryEntry($g['level'], $g['message'], $g['count'], $g['lastOccurrence']),
            $groups
        );
    }

    /**
     * Filter entries to only errors and warnings.
     *
     * @param LogEntry[] $entries
     * @return LogEntry[]
     */
    public function filterErrorsAndWarnings(array $entries): array
    {
        return array_values(array_filter(
            $entries,
            fn (LogEntry $e) => in_array($e->level, ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY', 'WARNING'], true)
        ));
    }

    /**
     * Filter entries to those occurring after a given cutoff time.
     *
     * @param LogEntry[] $entries
     * @return LogEntry[]
     */
    public function filterSince(array $entries, \DateTimeImmutable $since): array
    {
        return array_values(array_filter(
            $entries,
            fn (LogEntry $e) => $e->timestamp >= $since
        ));
    }

    /**
     * Parse a human-friendly duration string (e.g. "10m", "2h", "30s") into seconds.
     */
    public function parseDuration(string $duration): int
    {
        if (!preg_match('/^(\d+)\s*(s|m|h|d)$/i', trim($duration), $matches)) {
            throw new \InvalidArgumentException("Invalid duration format: '$duration'. Use e.g. 30s, 10m, 2h, 1d.");
        }

        $value = (int) $matches[1];

        return match (strtolower($matches[2])) {
            's' => $value,
            'm' => $value * 60,
            'h' => $value * 3600,
            'd' => $value * 86400,
            default => throw new \InvalidArgumentException("Unsupported duration unit: '{$matches[2]}'"),
        };
    }

    /**
     * Strip inline JSON context and exception object dumps to produce a
     * groupable message key.
     */
    private function normaliseMessage(string $message): string
    {
        // Remove trailing JSON context blobs like {"exception":"[object] (...)"}
        $message = preg_replace('/\s*\{["\'].+$/s', '', $message) ?? $message;

        return trim($message);
    }

    /**
     * @param list<string> $traceLines
     */
    private function buildEntry(string $timestamp, string $level, string $message, array $traceLines): LogEntry
    {
        $trace = null;
        $traceText = trim(implode("\n", $traceLines));
        if ($traceText !== '') {
            $trace = $traceText;
        }

        return new LogEntry(
            timestamp: new \DateTimeImmutable($timestamp),
            level: $level,
            message: $message,
            stackTrace: $trace,
        );
    }
}
