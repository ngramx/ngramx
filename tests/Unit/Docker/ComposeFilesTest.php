<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Docker;

use Ngramx\Docker\ComposeFiles;
use PHPUnit\Framework\TestCase;

class ComposeFilesTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/ngramx-compose-files-' . uniqid();
        mkdir($this->tempDir);
        file_put_contents($this->tempDir . '/docker-compose.yml', "services: {}\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function test_only_base_file_when_no_overrides_exist(): void
    {
        $compose = $this->tempDir . '/docker-compose.yml';

        $this->assertSame(['-f', $compose], ComposeFiles::fileArgs($compose));
        $this->assertSame([], ComposeFiles::layeredFiles($compose));
    }

    public function test_generated_override_is_layered_after_base(): void
    {
        $compose = $this->tempDir . '/docker-compose.yml';
        $override = $this->tempDir . '/' . ComposeFiles::OVERRIDE_FILE;
        file_put_contents($override, "services: {}\n");

        $this->assertSame(
            ['-f', $compose, '-f', $override],
            ComposeFiles::fileArgs($compose)
        );
    }

    public function test_user_override_is_layered_last_so_it_wins(): void
    {
        $compose = $this->tempDir . '/docker-compose.yml';
        $override = $this->tempDir . '/' . ComposeFiles::OVERRIDE_FILE;
        $user = $this->tempDir . '/' . ComposeFiles::USER_FILE;
        file_put_contents($override, "services: {}\n");
        file_put_contents($user, "services: {}\n");

        $this->assertSame(
            ['-f', $compose, '-f', $override, '-f', $user],
            ComposeFiles::fileArgs($compose)
        );
    }

    public function test_user_override_layered_even_without_generated_override(): void
    {
        $compose = $this->tempDir . '/docker-compose.yml';
        $user = $this->tempDir . '/' . ComposeFiles::USER_FILE;
        file_put_contents($user, "services: {}\n");

        $this->assertSame(
            ['-f', $compose, '-f', $user],
            ComposeFiles::fileArgs($compose)
        );
    }
}
