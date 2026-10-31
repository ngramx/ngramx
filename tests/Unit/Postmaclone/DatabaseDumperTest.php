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

    public function test_mysql_dump_streams_through_gzip_and_leaves_no_plain_sql(): void
    {
        $dir = sys_get_temp_dir() . '/ngramx-dumper-mysql-' . uniqid('', true);
        mkdir($dir, 0700, true);
        $bin = $dir . '/mysqldump';
        file_put_contents(
            $bin,
            <<<'SH'
#!/bin/sh
for arg in "$@"; do
  if [ "$arg" = "--help" ]; then
    echo column-statistics
    echo set-gtid-purged
    exit 0
  fi
done
printf '%s\n' '-- streamed dump payload --'
SH
        );
        chmod($bin, 0755);

        $oldPath = getenv('PATH');
        putenv('PATH=' . $dir . ':' . (is_string($oldPath) ? $oldPath : '/usr/bin'));
        try {
            $out = $dir . '/hydra_anon.sql.gz';
            $written = (new DatabaseDumper())->dump(
                'mysql://anon:secret@127.0.0.1:3306/hydra_prod_scratch',
                'mysql',
                $out,
            );
        } finally {
            putenv('PATH=' . (is_string($oldPath) ? $oldPath : '/usr/bin'));
        }

        $this->assertSame($out, $written);
        $this->assertFileExists($out);
        $this->assertFileDoesNotExist($dir . '/hydra_anon.sql');
        $this->assertSame("-- streamed dump payload --\n", (string) gzdecode((string) file_get_contents($out)));

        @unlink($bin);
        @unlink($out);
        @rmdir($dir);
    }

    public function test_mysql_dump_without_gzip_writes_plain_sql(): void
    {
        $dir = sys_get_temp_dir() . '/ngramx-dumper-mysql-plain-' . uniqid('', true);
        mkdir($dir, 0700, true);
        $bin = $dir . '/mysqldump';
        file_put_contents(
            $bin,
            <<<'SH'
#!/bin/sh
printf '%s\n' 'SELECT 1;'
SH
        );
        chmod($bin, 0755);

        $oldPath = getenv('PATH');
        putenv('PATH=' . $dir . ':' . (is_string($oldPath) ? $oldPath : '/usr/bin'));
        try {
            $out = $dir . '/scratch.sql';
            $written = (new DatabaseDumper())->dump(
                'mysql://anon:secret@127.0.0.1:3306/scratch',
                'mysql',
                $out,
                gzip: false,
            );
        } finally {
            putenv('PATH=' . (is_string($oldPath) ? $oldPath : '/usr/bin'));
        }

        $this->assertSame($out, $written);
        $this->assertSame("SELECT 1;\n", (string) file_get_contents($out));
        $this->assertFileDoesNotExist($out . '.gz');

        @unlink($bin);
        @unlink($out);
        @rmdir($dir);
    }
}
