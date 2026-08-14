<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Postmaclone\Backup\LocalBackupSource;
use Ngramx\Postmaclone\Exception\PostmacloneException;
use PHPUnit\Framework\TestCase;

final class LocalBackupSourceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pm-local-src-' . uniqid('', true);
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function test_last_modified_is_the_dump_file_mtime(): void
    {
        $path = $this->dir . '/anon.sql';
        file_put_contents($path, '-- dump --');
        $mtime = time() - 48 * 3600;
        touch($path, $mtime);

        $source = new LocalBackupSource($path);

        self::assertSame($mtime, $source->lastModified());
        self::assertSame($mtime, $source->probe()['modified_at'] ?? null);
    }

    public function test_last_modified_throws_when_file_missing(): void
    {
        $path = $this->dir . '/missing.sql';
        $source = new LocalBackupSource($path);

        $this->expectException(PostmacloneException::class);
        $this->expectExceptionMessage("Dump file not found: {$path}");

        $source->lastModified();
    }

    public function test_probe_reports_missing_file_without_throwing(): void
    {
        $path = $this->dir . '/missing.sql';
        $source = new LocalBackupSource($path);

        $probe = $source->probe();

        self::assertFalse($probe['exists']);
        self::assertSame("Missing file: {$path}", $probe['detail'] ?? null);
    }
}
