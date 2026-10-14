<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Config;

use Ngramx\Config\ConfigLoader;
use Ngramx\Config\Exception\ConfigException;
use Ngramx\Config\Validator\ConfigValidator;
use PHPUnit\Framework\TestCase;

class ConfigLoaderTunnelTest extends TestCase
{
    private ConfigLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new ConfigLoader(new ConfigValidator());
    }

    public function test_load_factory_rejects_tunnel_profile(): void
    {
        $path = dirname(__DIR__, 2) . '/fixtures/postmaclone/tunnel-profile.yml';

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('tunnel_profile');
        $this->loader->loadFactory($path);
    }

    public function test_load_tunnel_factory_accepts_tunnel_profile(): void
    {
        $path = dirname(__DIR__, 2) . '/fixtures/postmaclone/tunnel-profile.yml';

        $factory = $this->loader->loadTunnelFactory($path);
        $this->assertArrayHasKey('postgres', $factory->engines);
        $this->assertSame([], $factory->datasets);
    }
}
