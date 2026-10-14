<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Postmaclone\Connect\PostmacloneConnectLockData;
use Ngramx\Postmaclone\Connect\PostmacloneTunnelLock;
use PHPUnit\Framework\TestCase;

class PostmacloneTunnelLockTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pm-tunnel-lock-' . uniqid('', true);
        mkdir($this->dir . '/.ngramx', 0700, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/.ngramx/postmaclone-tunnel-postgres.lock');
        @rmdir($this->dir . '/.ngramx');
        @rmdir($this->dir);
    }

    public function test_it_round_trips_lock_data_under_custom_state_root(): void
    {
        $lock = new PostmacloneTunnelLock('postgres', $this->dir);
        $data = new PostmacloneConnectLockData(
            mode: PostmacloneConnectLockData::MODE_TUNNEL,
            engine: 'postgres',
            connectedAt: '2026-01-01T00:00:00+00:00',
            host: '127.0.0.1',
            port: 15432,
            database: 'postgres',
            username: 'anon',
            password: 'secret',
            databaseUrl: 'postgresql://anon:secret@127.0.0.1:15432/postgres',
            ideHost: '127.0.0.1',
            idePort: 15432,
            remoteHost: 'db.example.com',
            remotePort: 25060,
            localPort: 15432,
            tunnelPid: 4242,
        );

        $lock->write($data);
        $this->assertTrue($lock->exists());
        $read = $lock->read();
        $this->assertNotNull($read);
        $this->assertSame(4242, $read->tunnelPid);
        $this->assertSame('db.example.com', $read->remoteHost);

        $lock->delete();
        $this->assertFalse($lock->exists());
    }
}
