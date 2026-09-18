<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Restore;

use Ngramx\Postmaclone\Exception\PostmacloneException;

/**
 * MySQL 8 rejects sql_mode values removed after 5.7. Hydra (and any
 * mysqldump-5.7) dumps set NO_AUTO_CREATE_USER on tables and 50003-style
 * routine/trigger blocks. Strip that token only; leave other modes alone.
 */
final class MysqlDumpSanitizer
{
    public const FILTER_NAME = 'ngramx.mysql_dump_sanitize';

    public function rewriteLine(string $line): string
    {
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
}
