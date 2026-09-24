<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Restore;

use Ngramx\Postmaclone\Progress\PercentReporter;

/**
 * Counts bytes read through a stream and reports percent complete.
 */
final class StreamByteProgressFilter extends \php_user_filter
{
    public const FILTER_NAME = 'ngramx.stream_byte_progress';

    public function filter($in, $out, &$consumed, $closing): int
    {
        $params = $this->params;
        $reporter = is_array($params) && ($params['reporter'] ?? null) instanceof PercentReporter
            ? $params['reporter']
            : null;

        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += (int) $bucket->datalen;
            $reporter?->add((int) $bucket->datalen);
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
