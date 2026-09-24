<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Postmaclone\Connect\PostmacloneConnectLock;
use Ngramx\Postmaclone\Connect\PostmacloneConnectLockData;
use PHPUnit\Framework\TestCase;

class PostmacloneConnectLockTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pm-connect-lock-' . uniqid('', true);
        mkdir($this->dir . '/.ngramx', 0700, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/.ngramx/postmaclone-connect.lock');
        @rmdir($this->dir . '/.ngramx');
        @rmdir($this->dir);
    }

    public function test_round_trip_lock_file(): void
    {
        $lock = new PostmacloneConnectLock($this->dir);
        $data = new PostmacloneConnectLockData(
            mode: PostmacloneConnectLockData::MODE_TUNNEL,
            engine: 'postgres',
            connectedAt: '2026-01-01T00:00:00+00:00',
            host: 'host.docker.internal',
            port: 15432,
            database: 'demo_anon',
            username: 'anon',
            password: 'secret',
            databaseUrl: 'postgresql://anon:secret@host.docker.internal:15432/demo_anon',
            ideHost: '127.0.0.1',
            idePort: 15432,
            remoteHost: 'db.internal',
            remotePort: 25060,
            localPort: 15432,
            tunnelPid: 999,
        );

        $lock->write($data);
        $this->assertTrue($lock->exists());
        $read = $lock->read();
        $this->assertNotNull($read);
        $this->assertSame('tunnel', $read->mode);
        $this->assertSame(15432, $read->localPort);
        $this->assertSame(999, $read->tunnelPid);

        $lock->delete();
        $this->assertFalse($lock->exists());
    }
}
