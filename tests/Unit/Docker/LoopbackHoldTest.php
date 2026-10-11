<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Docker;

use Ngramx\Docker\LoopbackHold;
use PHPUnit\Framework\TestCase;

class LoopbackHoldTest extends TestCase
{
    public function test_it_flags_a_non_docker_process_on_loopback(): void
    {
        $fields = "p729\ncpostgres\nn127.0.0.1:5432\n";

        $this->assertTrue(LoopbackHold::nonDockerOwns($fields, 5432));
    }

    public function test_it_ignores_docker_and_wildcard_binds(): void
    {
        $fields = "p1\nccom.docker.backend\nn127.0.0.1:5432\np2\ncpostgres\nn[::1]:5432\n";

        $this->assertFalse(LoopbackHold::nonDockerOwns($fields, 5432));
    }

    public function test_it_ignores_a_different_port(): void
    {
        $fields = "p729\ncpostgres\nn127.0.0.1:5433\n";

        $this->assertFalse(LoopbackHold::nonDockerOwns($fields, 5432));
    }
}
