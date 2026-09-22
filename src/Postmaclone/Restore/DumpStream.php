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

    /**
     * Bytes used for auto remote-vs-Docker. Gzip ISIZE is uncompressed
     * modulo 4 GiB; if that is smaller than the file, add 4 GiB wraps
     * until the estimate is at least the on-disk size (empty gzip excepted).
     */
    public static function estimatedUncompressedBytes(string $path): ?int
    {
        if (!is_file($path)) {
            return null;
        }
        $onDisk = filesize($path);
        if ($onDisk === false) {
            return null;
        }
        if (!self::isGzip($path)) {
            return $onDisk;
        }

        $isize = self::gzipIsize($path);
        if ($isize === null) {
            return $onDisk;
        }
        if ($isize === 0 && $onDisk < 64) {
            return 0;
        }

        $mod = 4294967296;
        $estimate = $isize;
        while ($estimate < $onDisk) {
            $estimate += $mod;
        }

        return $estimate;
    }

    private static function gzipIsize(string $path): ?int
    {
        $size = filesize($path);
        if ($size === false || $size < 4) {
            return null;
        }
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }
        fseek($fh, -4, SEEK_END);
        $raw = fread($fh, 4);
        fclose($fh);
        if (!is_string($raw) || strlen($raw) !== 4) {
            return null;
        }
        $unpacked = unpack('Vsize', $raw);
        if (!is_array($unpacked)) {
            return null;
        }

        return (int) $unpacked['size'];
    }
}
