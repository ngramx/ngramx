<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Config;

use Ngramx\Config\DotEnvFileReader;
use PHPUnit\Framework\TestCase;

class DotEnvFileReaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ngramx-dotenv-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    public function test_it_returns_null_when_env_file_is_missing(): void
    {
        $reader = new DotEnvFileReader();

        $this->assertNull($reader->read($this->tmpDir . '/.env'));
    }

    public function test_it_parses_key_value_pairs_from_env_file(): void
    {
        file_put_contents($this->tmpDir . '/.env', "APP_KEY=secret\nDB_PASSWORD=pass\n");

        $reader = new DotEnvFileReader();
        $values = $reader->read($this->tmpDir . '/.env');

        $this->assertSame([
            'APP_KEY' => 'secret',
            'DB_PASSWORD' => 'pass',
        ], $values);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
