<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Config\Schema\DockerConfig;
use Ngramx\Config\Schema\N8nConfig;
use Ngramx\Config\Schema\NgramxConfig;
use Ngramx\Config\Schema\SetupConfig;
use Ngramx\Postmaclone\PostmacloneDoctor;
use PHPUnit\Framework\TestCase;

final class PostmacloneDoctorTest extends TestCase
{
    public function testMissingSectionIsBlocking(): void
    {
        $config = new NgramxConfig(
            version: '1',
            docker: new DockerConfig('docker-compose.yml', 'app', 'http://localhost'),
            setup: new SetupConfig(),
            n8n: new N8nConfig('./.n8n'),
            postmaclone: null,
        );

        $diagnosis = (new PostmacloneDoctor())->diagnose($config, sys_get_temp_dir());

        self::assertFalse($diagnosis['ok']);
        self::assertTrue($diagnosis['checks'][0]['blocking']);
        self::assertStringContainsString('Missing postmaclone', $diagnosis['checks'][0]['message']);
    }
}
