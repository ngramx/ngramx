<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Config\Schema\Postmaclone\BackupConfig;
use Ngramx\Config\Schema\Postmaclone\PostmacloneConfig;
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
}
