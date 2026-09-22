<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Restore;

use Ngramx\Postmaclone\Exception\PostmacloneException;

/**
 * Opens a dump for read. Gzip is decompressed through zlib — never expanded to disk.
 * Cache files are named `.dump` even when the object is `.sql.gz`, so detection
 * uses magic bytes, not the filename.
 */
final class DumpStream
{
    /**
     * @return resource
     */
    public static function open(string $path)
    {
        if (!is_file($path)) {
            throw new PostmacloneException("Dump not found: {$path}");
        }

        if (self::isGzip($path)) {
            $real = realpath($path);
            if ($real === false) {
                throw new PostmacloneException("Dump not found: {$path}");
            }
            $stream = fopen('compress.zlib://' . $real, 'rb');
        } else {
            $stream = fopen($path, 'rb');
        }

        if ($stream === false) {
            throw new PostmacloneException("Failed to open dump: {$path}");
        }

        return $stream;
    }

    public static function isGzip(string $path): bool
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $magic = fread($fh, 2);
        fclose($fh);

        return $magic === "\x1f\x8b";
    }
}
