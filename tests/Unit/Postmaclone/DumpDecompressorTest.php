<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Postmaclone\Exception\PostmacloneException;
use Ngramx\Postmaclone\Restore\DumpDecompressor;
use PHPUnit\Framework\TestCase;

final class DumpDecompressorTest extends TestCase
{
    public function test_streams_gzip_to_plain_file(): void
    {
        $dir = sys_get_temp_dir() . '/ngramx-decompress-' . uniqid('', true);
        mkdir($dir, 0700, true);
        $gz = $dir . '/dump.sql.gz';
        $payload = str_repeat("INSERT INTO users VALUES ('" . str_repeat('x', 200) . "');\n", 80);
        $compressed = gzencode($payload, 6);
        self::assertNotFalse($compressed);
        file_put_contents($gz, $compressed);

        try {
            $out = (new DumpDecompressor())->maybeDecompress($gz);
            self::assertSame($dir . '/dump.sql', $out);
            self::assertFileExists($out);
            self::assertSame($payload, file_get_contents($out));
        } finally {
            @unlink($dir . '/dump.sql');
            @unlink($gz);
            @rmdir($dir);
        }
    }

    public function test_leaves_plain_sql_unchanged(): void
    {
        $path = '/tmp/example.sql';
        self::assertSame($path, (new DumpDecompressor())->maybeDecompress($path));
    }

    public function test_corrupt_gzip_throws_and_removes_partial(): void
    {
        $dir = sys_get_temp_dir() . '/ngramx-decompress-' . uniqid('', true);
        mkdir($dir, 0700, true);
        $gz = $dir . '/dump.sql.gz';
        $plain = $dir . '/dump.sql';
        $compressed = gzencode(str_repeat("INSERT INTO users VALUES (1);\n", 200), 6);
        self::assertNotFalse($compressed);
        file_put_contents($gz, substr($compressed, 0, -4) . 'XXXX');

        try {
            (new DumpDecompressor())->maybeDecompress($gz);
            self::fail('expected PostmacloneException for a corrupt gzip');
        } catch (PostmacloneException) {
            self::assertFileDoesNotExist($plain);
        } finally {
            @unlink($plain);
            @unlink($gz);
            @rmdir($dir);
        }
    }
}
