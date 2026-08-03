<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Postmaclone\Backup\DatabaseDumper;
use PHPUnit\Framework\TestCase;

class DatabaseDumperTest extends TestCase
{
    public function test_gzip_round_trip_helper_path(): void
    {
        $dumper = new DatabaseDumper();
        $ref = new \ReflectionClass($dumper);
        $method = $ref->getMethod('gzipFile');
        $method->setAccessible(true);

        $dir = sys_get_temp_dir() . '/ngramx-dumper-' . uniqid('', true);
        mkdir($dir, 0700, true);
        $src = $dir . '/plain.sql';
        $dest = $dir . '/plain.sql.gz';
        file_put_contents($src, "SELECT 1;\n");
        $method->invoke($dumper, $src, $dest);

        $this->assertFileExists($dest);
        $this->assertSame("SELECT 1;\n", (string) gzdecode((string) file_get_contents($dest)));

        @unlink($src);
        @unlink($dest);
        @rmdir($dir);
    }
}
