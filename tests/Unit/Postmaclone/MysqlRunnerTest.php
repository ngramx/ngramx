<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Postmaclone\Restore\MysqlRunner;
use Ngramx\Postmaclone\Target\EphemeralTarget;
use PHPUnit\Framework\TestCase;

final class MysqlRunnerTest extends TestCase
{
    public function test_docker_restore_uses_docker_exec_not_compose_alias(): void
    {
        $cmd = (new MysqlRunner())->command($this->dockerTarget(
            host: 'db',
            port: 3306,
            container: 'ngramx-postmaclone-abc123',
            bindHost: '127.0.0.1',
            bindPort: 49152,
        ));

        $this->assertSame([
            'docker', 'exec', '-i',
            '-e', 'MYSQL_PWD',
            'ngramx-postmaclone-abc123',
            'mysql',
            '-h', '127.0.0.1',
            '-u', 'postmaclone',
            'postmaclone',
        ], $cmd);
        $this->assertNotContains('db', $cmd);
        $this->assertNotContains('3306', $cmd);
        $this->assertNotContains('49152', $cmd);
    }

    public function test_docker_without_container_uses_host_bind(): void
    {
        $cmd = (new MysqlRunner())->command($this->dockerTarget(
            host: 'db',
            port: 3306,
            container: null,
            bindHost: '127.0.0.1',
            bindPort: 49152,
        ));

        $this->assertSame([
            'mysql',
            '-h', '127.0.0.1',
            '-P', '49152',
            '-u', 'postmaclone',
            'postmaclone',
        ], $cmd);
        $this->assertNotContains('db', $cmd);
        $this->assertNotContains('3306', $cmd);
    }

    public function test_remote_restore_uses_host_bind_when_present(): void
    {
        $cmd = (new MysqlRunner())->command(new EphemeralTarget(
            provider: 'remote',
            engine: 'mysql',
            host: 'db.internal',
            port: 3306,
            database: 'app',
            username: 'clone',
            password: 'secret',
            databaseUrl: 'mysql://clone:secret@db.internal:3306/app',
            expiresAt: '2026-08-14T16:00:00+00:00',
            meta: [
                'host_bind_host' => '127.0.0.1',
                'host_bind_port' => 3307,
            ],
        ));

        $this->assertSame([
            'mysql',
            '-h', '127.0.0.1',
            '-P', '3307',
            '-u', 'clone',
            'app',
        ], $cmd);
    }

    public function test_host_restore_falls_back_to_target_host_port(): void
    {
        $cmd = (new MysqlRunner())->command(new EphemeralTarget(
            provider: 'remote',
            engine: 'mysql',
            host: '127.0.0.1',
            port: 3306,
            database: 'app',
            username: 'clone',
            password: 'secret',
            databaseUrl: 'mysql://clone:secret@127.0.0.1:3306/app',
            expiresAt: '2026-08-14T16:00:00+00:00',
        ));

        $this->assertSame([
            'mysql',
            '-h', '127.0.0.1',
            '-P', '3306',
            '-u', 'clone',
            'app',
        ], $cmd);
    }

    private function dockerTarget(
        string $host,
        int $port,
        ?string $container,
        string $bindHost,
        int $bindPort,
    ): EphemeralTarget {
        $meta = [
            'host_bind_host' => $bindHost,
            'host_bind_port' => $bindPort,
        ];
        if ($container !== null) {
            $meta['container_name'] = $container;
        }

        return new EphemeralTarget(
            provider: 'docker',
            engine: 'mysql',
            host: $host,
            port: $port,
            database: 'postmaclone',
            username: 'postmaclone',
            password: 'secret',
            databaseUrl: "mysql://postmaclone:secret@{$host}:{$port}/postmaclone",
            expiresAt: '2026-08-14T16:00:00+00:00',
            meta: $meta,
        );
    }
}
