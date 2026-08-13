<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Http;

use Ngramx\Http\LoopbackUrl;
use PHPUnit\Framework\TestCase;

class LoopbackUrlTest extends TestCase
{
    public function test_it_rewrites_localhost_to_loopback_ip(): void
    {
        $this->assertSame(
            [
                'url' => 'http://127.0.0.1:80/',
                'host' => 'localhost',
            ],
            LoopbackUrl::probeTarget('http://localhost:80')
        );
    }

    public function test_it_rewrites_dot_localhost_preserving_port_and_path(): void
    {
        $this->assertSame(
            [
                'url' => 'https://127.0.0.1:8543/app',
                'host' => 'terrablock.localhost',
            ],
            LoopbackUrl::probeTarget('https://terrablock.localhost:8543/app')
        );
    }

    public function test_it_defaults_https_port_and_empty_path_to_slash(): void
    {
        $this->assertSame(
            [
                'url' => 'https://127.0.0.1:443/',
                'host' => 'app.localhost',
            ],
            LoopbackUrl::probeTarget('https://app.localhost')
        );
    }

    public function test_it_returns_null_for_non_localhost_hosts(): void
    {
        $this->assertNull(LoopbackUrl::probeTarget('https://dev.hydra:8080/'));
    }

    public function test_it_returns_null_for_loopback_ip(): void
    {
        $this->assertNull(LoopbackUrl::probeTarget('https://127.0.0.1:8543/'));
    }

    public function test_with_host_replaces_only_the_hostname(): void
    {
        $this->assertSame(
            'https://terrablock.localhost:8543/app?x=1',
            LoopbackUrl::withHost('https://127.0.0.1:8543/app?x=1', 'terrablock.localhost')
        );
    }
}
