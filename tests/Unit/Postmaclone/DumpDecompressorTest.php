<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Postmaclone\Restore\DumpDecompressor;
use PHPUnit\Framework\TestCase;

final class DumpDecompressorTest extends TestCase
{
    public function test_leaves_gzip_on_disk(): void
    {
        $dir = sys_get_temp_dir() . '/ngramx-decompress-' . uniqid('', true);
        mkdir($dir, 0700, true);
        $gz = $dir . '/dump.sql.gz';
        $payload = "INSERT INTO users VALUES (1);\n";
        $compressed = gzencode($payload, 6);
        self::assertNotFalse($compressed);
        file_put_contents($gz, $compressed);

        try {
            $out = (new DumpDecompressor())->maybeDecompress($gz);
            self::assertSame($gz, $out);
            self::assertFileExists($gz);
            self::assertFileDoesNotExist($dir . '/dump.sql');
            self::assertSame($compressed, file_get_contents($gz));
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
}
