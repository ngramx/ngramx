<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Postmaclone\Backup\PostgresDumpBinary;
use PHPUnit\Framework\TestCase;

final class PostgresDumpBinaryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/pm-pgdump-' . uniqid('', true);
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
    }

    public function test_prefers_the_newest_versioned_binary(): void
    {
        $this->touchVersion(16);
        $this->touchVersion(17);

        self::assertSame(
            $this->root . '/17/bin/pg_dump',
            PostgresDumpBinary::newestVersioned($this->root, 'pg_dump')
        );
        self::assertSame(
            $this->root . '/17/bin/pg_dump',
            PostgresDumpBinary::resolve($this->root)
        );
    }

    public function test_falls_back_when_no_versioned_binary_exists(): void
    {
        $resolved = PostgresDumpBinary::resolve($this->root . '/missing');
        self::assertNotSame($this->root . '/missing/17/bin/pg_dump', $resolved);
    }

    public function test_version_from_path(): void
    {
        self::assertSame(17, PostgresDumpBinary::versionFromPath('/usr/lib/postgresql/17/bin/pg_dump'));
        self::assertSame(0, PostgresDumpBinary::versionFromPath('/usr/bin/pg_dump'));
    }

    private function touchVersion(int $version): void
    {
        $dir = $this->root . '/' . $version . '/bin';
        mkdir($dir, 0700, true);
        file_put_contents($dir . '/pg_dump', "#!/bin/sh\necho {$version}\n");
        chmod($dir . '/pg_dump', 0755);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
