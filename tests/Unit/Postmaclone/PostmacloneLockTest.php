<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Postmaclone\PostmacloneLock;
use Ngramx\Postmaclone\PostmacloneLockData;
use PHPUnit\Framework\TestCase;

class PostmacloneLockTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/postmaclone-lock-' . uniqid('', true);
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        $lock = new PostmacloneLock($this->dir);
        $lock->delete();
        if (is_dir($this->dir . '/.ngramx')) {
            @rmdir($this->dir . '/.ngramx');
        }
        @rmdir($this->dir);
    }

    public function test_write_read_delete_roundtrip(): void
    {
        $lockFile = new PostmacloneLock($this->dir);
        $this->assertFalse($lockFile->exists());

        $data = new PostmacloneLockData(
            provider: 'docker',
            engine: 'postgres',
            createdAt: '2026-08-02T00:00:00+00:00',
            expiresAt: '2026-08-02T04:00:00+00:00',
            host: '127.0.0.1',
            port: 5432,
            database: 'postmaclone',
            username: 'u',
            password: 'p',
            databaseUrl: 'postgresql://u:p@127.0.0.1:5432/postmaclone',
            label: 'gig-1',
            providerMeta: ['container_name' => 'x'],
        );
        $lockFile->write($data);

        $this->assertTrue($lockFile->exists());
        $read = $lockFile->read();
        $this->assertNotNull($read);
        $this->assertSame('docker', $read->provider);
        $this->assertSame('gig-1', $read->label);
        $this->assertSame('x', $read->providerMeta['container_name']);

        $lockFile->delete();
        $this->assertFalse($lockFile->exists());
    }
}
