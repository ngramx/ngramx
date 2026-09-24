<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Restore;

/**
 * Gzip dumps stay on disk as gzip. Restorers decompress through DumpStream.
 */
final class DumpDecompressor
{
    public function maybeDecompress(string $path): string
    {
        return $path;
    }
}
