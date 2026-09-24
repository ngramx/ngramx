<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Codabyte\ServerTarget;
use Ngramx\Postmaclone\Connect\SshTunnelManager;
use PHPUnit\Framework\TestCase;

class SshTunnelManagerTest extends TestCase
{
    public function test_build_tunnel_args_includes_local_forward(): void
    {
        $target = new ServerTarget(host: 'codabyte.test', sshUser: 'forge', port: 2222);
        $args = (new SshTunnelManager($target))->buildTunnelArgs(15432, 'db.internal', 25060);

        $this->assertSame('ssh', $args[0]);
        $this->assertContains('-L', $args);
        $this->assertContains('127.0.0.1:15432:db.internal:25060', $args);
        $this->assertContains('-p', $args);
        $this->assertContains('2222', $args);
        $this->assertSame('forge@codabyte.test', $args[array_key_last($args)]);
    }
}
