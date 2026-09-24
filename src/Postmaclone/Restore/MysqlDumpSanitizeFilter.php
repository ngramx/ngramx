<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Restore;

/**
 * Line-buffered php_user_filter so sanitizer tokens are not split across
 * stream buckets. Huge INSERT lines stay in the carry buffer until newline.
 */
final class MysqlDumpSanitizeFilter extends \php_user_filter
{
    private string $carry = '';

    private MysqlDumpSanitizer $sanitizer;

    public function onCreate(): bool
    {
        $this->sanitizer = new MysqlDumpSanitizer();

        return true;
    }

    public function filter($in, $out, &$consumed, $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += (int) $bucket->datalen;
            $this->carry .= $bucket->data;
            $bucket->data = $this->flushLines((bool) $closing);
            stream_bucket_append($out, $bucket);
        }

        if ($closing && $this->carry !== '') {
            $tail = stream_bucket_new($this->stream, $this->sanitizer->rewriteLine($this->carry));
            $this->carry = '';
            stream_bucket_append($out, $tail);
        }

        return PSFS_PASS_ON;
    }

    private function flushLines(bool $closing): string
    {
        $out = '';
        while (($pos = strpos($this->carry, "\n")) !== false) {
            $line = substr($this->carry, 0, $pos + 1);
            $this->carry = substr($this->carry, $pos + 1);
            $out .= $this->sanitizer->rewriteLine($line);
        }

        if ($closing) {
            $out .= $this->sanitizer->rewriteLine($this->carry);
            $this->carry = '';
        }

        return $out;
    }
}
