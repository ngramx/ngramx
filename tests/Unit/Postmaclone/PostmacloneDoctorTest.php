<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Config\Schema\DockerConfig;
use Ngramx\Config\Schema\N8nConfig;
use Ngramx\Config\Schema\NgramxConfig;
use Ngramx\Config\Schema\Postmaclone\BackupConfig;
use Ngramx\Config\Schema\Postmaclone\ColumnRule;
use Ngramx\Config\Schema\Postmaclone\PostmacloneConfig;
use Ngramx\Config\Schema\Postmaclone\PrebuiltConfig;
use Ngramx\Config\Schema\Postmaclone\TableRule;
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
        self::assertFalse($diagnosis['needs_s3']);
    }

    public function test_connection_source_skips_op_and_s3_checks(): void
    {
        $config = $this->config(new BackupConfig(source: BackupConfig::SOURCE_CONNECTION));
        $diagnosis = (new PostmacloneDoctor())->diagnose($config, sys_get_temp_dir());

        self::assertFalse($diagnosis['needs_s3']);
        self::assertSame([], $diagnosis['next_steps']);
        foreach ($diagnosis['checks'] as $check) {
            self::assertStringNotContainsStringIgnoringCase('1Password', $check['message']);
            self::assertStringNotContainsStringIgnoringCase('backup.credentials', $check['message']);
            self::assertStringNotContainsStringIgnoringCase('S3 credentials', $check['message']);
            self::assertStringNotContainsString('op account', $check['message']);
        }
    }

    public function test_local_source_skips_op_and_s3_checks(): void
    {
        $config = $this->config(new BackupConfig(
            source: BackupConfig::SOURCE_LOCAL,
            path: './backups/latest.dump',
        ));
        $diagnosis = (new PostmacloneDoctor())->diagnose($config, sys_get_temp_dir());

        self::assertFalse($diagnosis['needs_s3']);
        self::assertSame([], $diagnosis['next_steps']);
    }

    public function test_s3_backup_source_needs_credentials(): void
    {
        $config = $this->config(new BackupConfig(
            source: BackupConfig::SOURCE_S3,
            path: 'spaces://bucket/path/',
            file: 'dump.sql.gz',
        ));

        self::assertTrue((new PostmacloneDoctor())->needsS3Credentials($config));
    }

    public function test_s3_prebuilt_needs_credentials(): void
    {
        $config = new NgramxConfig(
            version: '1',
            docker: new DockerConfig('docker-compose.yml', 'app', 'http://localhost'),
            setup: new SetupConfig(),
            n8n: new N8nConfig('./.n8n'),
            postmaclone: new PostmacloneConfig(
                engine: 'mysql',
                backup: new BackupConfig(source: BackupConfig::SOURCE_CONNECTION),
                prebuilt: new PrebuiltConfig(
                    source: BackupConfig::SOURCE_S3,
                    path: 'spaces://anon-bucket/project/',
                    file: 'anon.sql.gz',
                ),
            ),
        );

        self::assertTrue((new PostmacloneDoctor())->needsS3Credentials($config));
    }

    private function config(BackupConfig $backup): NgramxConfig
    {
        return new NgramxConfig(
            version: '1',
            docker: new DockerConfig('docker-compose.yml', 'app', 'http://localhost'),
            setup: new SetupConfig(),
            n8n: new N8nConfig('./.n8n'),
            postmaclone: new PostmacloneConfig(
                engine: 'mysql',
                backup: $backup,
                tables: [
                    'users' => new TableRule(
                        table: 'users',
                        columns: [
                            'email' => new ColumnRule('email', 'safeEmail'),
                        ],
                    ),
                ],
            ),
        );
    }
}
