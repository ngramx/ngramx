<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Restore;

use Ngramx\Postmaclone\Exception\PostmacloneException;
use Symfony\Component\Process\Process;

/**
 * Decompress .gz dumps in place for restore tooling that expects plain SQL/custom files.
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

        $process = new Process(['gzip', '-dc', $path]);
        $process->setTimeout(3600);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new PostmacloneException('Failed to decompress dump: ' . $process->getErrorOutput());
        }

        if (file_put_contents($out, $process->getOutput()) === false) {
            throw new PostmacloneException("Failed to write decompressed dump: {$out}");
        }

        return $out;
    }
}
