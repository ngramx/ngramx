<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Restore;

use Ngramx\Postmaclone\Exception\PostmacloneException;

/**
 * Decompress .gz dumps for restore tooling that expects plain SQL/custom files.
 */
final class DumpDecompressor
{
    public function maybeDecompress(string $path): string
    {
        if (!str_ends_with(strtolower($path), '.gz')) {
            return $path;
        }

        $out = preg_replace('/\.gz$/i', '', $path);
        if (!is_string($out) || $out === '') {
            throw new PostmacloneException("Invalid gzip dump path: {$path}");
        }

        $in = gzopen($path, 'rb');
        if ($in === false) {
            throw new PostmacloneException("Failed to open gzip dump: {$path}");
        }
        $dest = fopen($out, 'wb');
        if ($dest === false) {
            gzclose($in);
            throw new PostmacloneException("Failed to write decompressed dump: {$out}");
        }

        try {
            while (!gzeof($in)) {
                $chunk = gzread($in, 1024 * 1024);
                if ($chunk === false) {
                    break;
                }
                if (fwrite($dest, $chunk) === false) {
                    throw new PostmacloneException("Failed to write decompressed dump: {$out}");
                }
            }
        } finally {
            gzclose($in);
            fclose($dest);
        }

        return $out;
    }
}
