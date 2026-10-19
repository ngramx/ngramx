<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Docker;

use Ngramx\Docker\PortPublishFailure;
use PHPUnit\Framework\TestCase;

class PortPublishFailureTest extends TestCase
{
    public function test_it_parses_docker_desktop_forwards_expose_500(): void
    {
        $message = 'Error response from daemon: ports are not available: '
            . 'exposing port TCP 0.0.0.0:64506 -> 127.0.0.1:0: '
            . '/forwards/expose returned unexpected status: 500';

        $this->assertSame([64506], PortPublishFailure::parsePorts($message));
    }

    public function test_it_parses_bind_already_in_use(): void
    {
        $message = 'Error starting userland proxy: listen tcp4 0.0.0.0:8280: bind: address already in use';

        $this->assertSame([8280], PortPublishFailure::parsePorts($message));
    }

    public function test_it_ignores_unrelated_compose_errors(): void
    {
        $this->assertSame([], PortPublishFailure::parsePorts(
            'error mounting "/run/desktop/mnt/host/wsl/docker-desktop-bind-mounts/Ubuntu/aaa": no such file or directory'
        ));
    }
}
