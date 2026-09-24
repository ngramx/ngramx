<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Postmaclone\Exception\PostmacloneException;
use Ngramx\Postmaclone\Restore\DumpStream;
use PHPUnit\Framework\TestCase;

final class DumpStreamTest extends TestCase
{
    public function test_detects_gzip_by_magic_not_filename(): void
    {
        $dir = sys_get_temp_dir() . '/ngramx-dump-stream-' . uniqid('', true);
        mkdir($dir, 0700, true);
        $path = $dir . '/postmaclone-abc.dump';
        $payload = "INSERT INTO users VALUES (1);\n";
        $compressed = gzencode($payload, 6);
        self::assertNotFalse($compressed);
        file_put_contents($path, $compressed);

        try {
            self::assertTrue(DumpStream::isGzip($path));
            $in = DumpStream::open($path);
            $read = stream_get_contents($in);
            fclose($in);
            self::assertSame($payload, $read);
            self::assertFileExists($path);
            self::assertSame($compressed, file_get_contents($path));
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }

    public function test_opens_plain_sql_unchanged(): void
    {
        $dir = sys_get_temp_dir() . '/ngramx-dump-stream-' . uniqid('', true);
        mkdir($dir, 0700, true);
        $path = $dir . '/postmaclone-abc.dump';
        $payload = "INSERT INTO users VALUES (1);\n";
        file_put_contents($path, $payload);

        try {
            self::assertFalse(DumpStream::isGzip($path));
            $in = DumpStream::open($path);
            $read = stream_get_contents($in);
            fclose($in);
            self::assertSame($payload, $read);
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }

    public function test_missing_dump_throws(): void
    {
        $this->expectException(PostmacloneException::class);
        $this->expectExceptionMessage('Dump not found');
        DumpStream::open('/tmp/ngramx-missing-dump-' . uniqid('', true));
    }

    public function test_estimate_uses_plain_filesize(): void
    {
        $dir = sys_get_temp_dir() . '/ngramx-dump-stream-' . uniqid('', true);
        mkdir($dir, 0700, true);
        $path = $dir . '/postmaclone-abc.dump';
        $payload = str_repeat("INSERT INTO users VALUES (1);\n", 20);
        file_put_contents($path, $payload);

        try {
            self::assertSame(strlen($payload), DumpStream::estimatedUncompressedBytes($path));
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }

    public function test_estimate_reads_gzip_isize(): void
    {
        $dir = sys_get_temp_dir() . '/ngramx-dump-stream-' . uniqid('', true);
        mkdir($dir, 0700, true);
        $path = $dir . '/postmaclone-abc.dump';
        $payload = str_repeat("INSERT INTO users VALUES (1);\n", 80);
        $compressed = gzencode($payload, 6);
        self::assertNotFalse($compressed);
        file_put_contents($path, $compressed);

        try {
            self::assertSame(strlen($payload), DumpStream::estimatedUncompressedBytes($path));
            self::assertGreaterThan(strlen($compressed), (int) DumpStream::estimatedUncompressedBytes($path));
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }

    public function test_estimate_unwraps_isize_when_smaller_than_on_disk(): void
    {
        $dir = sys_get_temp_dir() . '/ngramx-dump-stream-' . uniqid('', true);
        mkdir($dir, 0700, true);
        $path = $dir . '/postmaclone-abc.dump';
        $onDisk = 100;
        $isize = 10;
        $blob = "\x1f\x8b" . str_repeat('x', $onDisk - 6) . pack('V', $isize);
        file_put_contents($path, $blob);

        try {
            self::assertSame($isize + 4294967296, DumpStream::estimatedUncompressedBytes($path));
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }

    public function test_estimate_empty_gzip_is_zero(): void
    {
        $dir = sys_get_temp_dir() . '/ngramx-dump-stream-' . uniqid('', true);
        mkdir($dir, 0700, true);
        $path = $dir . '/postmaclone-abc.dump';
        $compressed = gzencode('', 6);
        self::assertNotFalse($compressed);
        file_put_contents($path, $compressed);

        try {
            self::assertSame(0, DumpStream::estimatedUncompressedBytes($path));
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }
}
