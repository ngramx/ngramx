<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Postmaclone\Backup\LocalBackupSource;
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

    public function test_last_modified_is_null_when_file_missing(): void
    {
        $source = new LocalBackupSource($this->dir . '/missing.sql');

        self::assertNull($source->lastModified());
    }
}
