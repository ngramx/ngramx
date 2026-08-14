<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Filesystem;

use Ngramx\Filesystem\AbsolutePath;
use PHPUnit\Framework\TestCase;

final class AbsolutePathTest extends TestCase
{
    public function test_unix_root_is_absolute(): void
    {
        $this->assertTrue(AbsolutePath::isAbsolute('/var/dump.sql'));
    }

    public function test_windows_drive_is_absolute(): void
    {
        $this->assertTrue(AbsolutePath::isAbsolute('C:\\Users\\wilki\\dump.sql'));
        $this->assertTrue(AbsolutePath::isAbsolute('C:/Users/wilki/dump.sql'));
    }

    public function test_relative_is_not_absolute(): void
    {
        $this->assertFalse(AbsolutePath::isAbsolute('backups/dump.sql'));
        $this->assertFalse(AbsolutePath::isAbsolute('./backups/dump.sql'));
        $this->assertFalse(AbsolutePath::isAbsolute(''));
    }

    public function test_resolve_keeps_absolute_windows_path(): void
    {
        $absolute = 'C:\\Users\\wilki\\Herd\\ngramx\\tests\\fixtures\\users.sql';

        $this->assertSame($absolute, AbsolutePath::resolve('C:\\Users\\wilki\\Herd\\ngramx', $absolute));
    }

    public function test_resolve_joins_relative_path(): void
    {
        $joined = AbsolutePath::resolve('/project', './backups/dump.sql');

        $this->assertSame('/project' . DIRECTORY_SEPARATOR . 'backups/dump.sql', $joined);
    }
}
