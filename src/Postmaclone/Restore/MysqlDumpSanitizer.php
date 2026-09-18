<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Restore;

use Ngramx\Postmaclone\Exception\PostmacloneException;

/**
 * Managed MySQL 8 (DigitalOcean) rejects several mysqldump-5.7 / SUPER-only
 * constructs. Rewrite on the restore stream; do not edit the Spaces dump.
 *
 * - NO_AUTO_CREATE_USER in sql_mode (removed after 5.7)
 * - DEFINER=`user`@`host` on views, triggers, routines, events
 * - SET ... SQL_LOG_BIN (needs SUPER)
 * - SET ... GTID_PURGED / GTID_NEXT / GTID_EXECUTED (needs SUPER)
 */
final class MysqlDumpSanitizer
{
    public const FILTER_NAME = 'ngramx.mysql_dump_sanitize';

    public function rewriteLine(string $line): string
    {
        if ($this->isSetAssignment($line, 'SQL_LOG_BIN')) {
            return $this->commented('SQL_LOG_BIN');
        }
        if ($this->isSetAssignment($line, 'GTID_PURGED')
            || $this->isSetAssignment($line, 'GTID_NEXT')
            || $this->isSetAssignment($line, 'GTID_EXECUTED')) {
            return $this->commented('GTID assignment');
        }

        if ($this->looksLikeDefinerDdl($line)) {
            $line = $this->stripDefiner($line);
        }

        if (!str_contains($line, 'NO_AUTO_CREATE_USER') || !preg_match('/\bsql_mode\b/i', $line)) {
            return $line;
        }

        $rewritten = preg_replace('/\bNO_AUTO_CREATE_USER\b/', '', $line) ?? $line;
        $rewritten = preg_replace("/(['\"])\s*,+\s*/", '$1', $rewritten) ?? $rewritten;
        $rewritten = preg_replace("/\s*,+\s*(['\"])/", '$1', $rewritten) ?? $rewritten;

        return preg_replace('/,\s*,+/', ',', $rewritten) ?? $rewritten;
    }

    public function registerFilter(): void
    {
        if (in_array(self::FILTER_NAME, stream_get_filters(), true)) {
            return;
        }

        stream_filter_register(self::FILTER_NAME, MysqlDumpSanitizeFilter::class);
    }

    /**
     * @param resource $stream
     * @return resource
     */
    public function appendFilter($stream)
    {
        $this->registerFilter();
        $filter = stream_filter_append($stream, self::FILTER_NAME, STREAM_FILTER_READ);
        if ($filter === false) {
            throw new PostmacloneException('Failed to attach MySQL dump sanitizer filter');
        }

        return $stream;
    }

    private function commented(string $reason): string
    {
        return '-- ngramx: stripped ' . $reason . "\n";
    }

    private function isSetAssignment(string $line, string $token): bool
    {
        return stripos($line, $token) !== false && preg_match('/\bSET\b/i', $line) === 1;
    }

    private function looksLikeDefinerDdl(string $line): bool
    {
        if (preg_match('/\bDEFINER\s*=/i', $line) !== 1) {
            return false;
        }

        return preg_match('/^\s*(CREATE|ALTER|\/\*!)/i', $line) === 1
            || preg_match('/\/\*![0-9]{5}\s*DEFINER\s*=/i', $line) === 1;
    }

    private function stripDefiner(string $line): string
    {
        $quoted = '(?:`[^`]+`|\'[^\']+\'|"[^"]+"|[A-Za-z0-9_.$%-]+)';
        $rewritten = preg_replace(
            '/\s*DEFINER\s*=\s*' . $quoted . '\s*@\s*' . $quoted . '/i',
            '',
            $line
        );

        return $rewritten ?? $line;
    }
}
