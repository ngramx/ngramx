<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Config\Schema\Postmaclone\BackupConfig;
use Ngramx\Config\Schema\Postmaclone\PostmacloneConfig;
use Ngramx\Config\Schema\Postmaclone\TargetConfig;
use Ngramx\Filesystem\HostBinary;
use Ngramx\Postmaclone\Restore\RestoreDoctor;
use PHPUnit\Framework\TestCase;

final class RestoreDoctorTest extends TestCase
{
    public function testReportsAclStripModelAndMissingFile(): void
    {
        $root = sys_get_temp_dir() . '/pm-doctor-' . uniqid('', true);
        mkdir($root, 0700, true);

        $pm = new PostmacloneConfig(
            engine: 'postgres',
            backup: new BackupConfig(
                source: BackupConfig::SOURCE_S3,
                path: 'spaces://bucket/database-backups/all/',
                // file intentionally missing
            ),
            tables: [],
        );

        try {
            $result = (new RestoreDoctor())->analyse($pm, $root);
            $messages = implode("\n", array_column($result['checks'], 'message'));
            self::assertStringContainsString('strip prod roles/ACLs', $messages);
            self::assertTrue(
                (bool) array_filter($result['checks'], static fn (array $c): bool => !$c['ok'])
            );
            self::assertStringContainsString('file:', implode("\n", $result['suggestions']));
        } finally {
            @rmdir($root);
        }
    }

    public function test_missing_psql_is_ok_for_docker_target(): void
    {
        if (HostBinary::exists('psql')) {
            $this->markTestSkipped('psql is on PATH; cannot assert missing-binary behaviour');
        }

        $pm = new PostmacloneConfig(
            engine: 'postgres',
            backup: new BackupConfig(source: BackupConfig::SOURCE_LOCAL, path: './dump.sql'),
            target: new TargetConfig(provider: TargetConfig::PROVIDER_DOCKER),
            tables: [],
        );

        $result = (new RestoreDoctor())->analyse($pm, sys_get_temp_dir());
        $psqlChecks = array_filter(
            $result['checks'],
            static fn (array $c): bool => str_contains($c['message'], 'psql not found')
        );

        $this->assertNotSame([], $psqlChecks);
        foreach ($psqlChecks as $check) {
            $this->assertTrue($check['ok']);
        }
    }
}
