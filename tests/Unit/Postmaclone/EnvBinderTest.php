<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Postmaclone\EnvBinder;
use Ngramx\Postmaclone\PostmacloneLockData;
use PHPUnit\Framework\TestCase;

class EnvBinderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/postmaclone-env-' . uniqid('', true);
        mkdir($this->dir . '/.ngramx', 0700, true);
        file_put_contents($this->dir . '/.env', implode("\n", [
            'APP_NAME=Test',
            'DB_HOST=old-host',
            'DB_PORT=5432',
            'DB_DATABASE=app',
            'DB_USERNAME=app',
            'DB_PASSWORD=secret',
            'SOMETHING_ELSE=1',
            '',
        ]));
    }

    protected function tearDown(): void
    {
        $files = [
            $this->dir . '/.env',
            $this->dir . '/.ngramx/postmaclone.env.bak',
        ];
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->dir . '/.ngramx')) {
            rmdir($this->dir . '/.ngramx');
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function test_bind_rewrites_only_present_db_keys_and_restores(): void
    {
        $binder = new EnvBinder($this->dir);
        $lock = new PostmacloneLockData(
            provider: 'docker',
            engine: 'postgres',
            createdAt: date('c'),
            expiresAt: date('c'),
            host: '127.0.0.1',
            port: 55432,
            database: 'postmaclone',
            username: 'postmaclone',
            password: 'newpass',
            databaseUrl: 'postgresql://postmaclone:newpass@127.0.0.1:55432/postmaclone',
        );

        $backup = $binder->bind($lock);
        $this->assertNotNull($backup);
        $this->assertFileExists($backup);

        $env = file_get_contents($this->dir . '/.env');
        $this->assertIsString($env);
        $this->assertStringContainsString('DB_HOST=127.0.0.1', $env);
        $this->assertStringContainsString('DB_PORT=55432', $env);
        $this->assertStringContainsString('DB_PASSWORD=newpass', $env);
        $this->assertStringContainsString('APP_NAME=Test', $env);
        $this->assertStringContainsString('SOMETHING_ELSE=1', $env);
        // DATABASE_URL was not in original .env — must not be appended
        $this->assertStringNotContainsString('DATABASE_URL=', $env);

        $this->assertTrue($binder->restore($backup));
        $restored = file_get_contents($this->dir . '/.env');
        $this->assertIsString($restored);
        $this->assertStringContainsString('DB_HOST=old-host', $restored);
        $this->assertStringContainsString('DB_PASSWORD=secret', $restored);
    }
}
