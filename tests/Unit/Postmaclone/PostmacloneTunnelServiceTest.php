<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Config\Schema\Postmaclone\DbCredentialsConfig;
use Ngramx\Config\Schema\Postmaclone\EngineConnectionsConfig;
use Ngramx\Config\Schema\Postmaclone\FactoryConfig;
use Ngramx\Postmaclone\Connect\PostmacloneTunnelLock;
use Ngramx\Postmaclone\Connect\PostmacloneTunnelService;
use Ngramx\Postmaclone\Exception\PostmacloneException;
use PHPUnit\Framework\TestCase;

class PostmacloneTunnelServiceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pm-tunnel-svc-' . uniqid('', true);
        mkdir($this->dir . '/.ngramx', 0700, true);
        putenv('HOME=' . $this->dir);
    }

    protected function tearDown(): void
    {
        putenv('HOME');
        @unlink($this->dir . '/.ngramx/postmaclone-tunnel-postgres.lock');
        @unlink($this->dir . '/.ngramx/postmaclone-tunnel-mysql.lock');
        @rmdir($this->dir . '/.ngramx');
        @rmdir($this->dir);
    }

    public function test_normalize_engine_aliases(): void
    {
        $service = new PostmacloneTunnelService();
        $this->assertSame('postgres', $service->normalizeEngine('psql'));
        $this->assertSame('mysql', $service->normalizeEngine('mysql'));
        $this->assertSame('psql', $service->engineCliFlag('postgres'));
    }

    public function test_open_requires_anon_credentials(): void
    {
        $factory = new FactoryConfig(engines: [
            'postgres' => new EngineConnectionsConfig(),
        ]);
        $service = new PostmacloneTunnelService();

        $this->expectException(PostmacloneException::class);
        $this->expectExceptionMessage('engines.postgres.anon');
        $service->open($factory, 'postgres', probe: false);
    }

    public function test_open_with_missing_engine_does_not_fatal(): void
    {
        $factory = new FactoryConfig(engines: [
            'postgres' => new EngineConnectionsConfig(
                anon: new DbCredentialsConfig(username: 'u', password: 'p', host: 'h', port: '5432'),
            ),
        ]);
        $service = new PostmacloneTunnelService();

        $this->expectException(PostmacloneException::class);
        $this->expectExceptionMessage('engines.mysql.anon');
        $service->open($factory, 'mysql', probe: false);
    }

    public function test_close_is_noop_when_no_lock(): void
    {
        $service = new PostmacloneTunnelService();
        $this->assertFalse($service->close('postgres'));
        $this->assertFalse((new PostmacloneTunnelLock('postgres', $this->dir))->exists());
    }

}
