<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Ngramx\Config\Schema\Postmaclone\BackupConfig;
use Ngramx\Config\Schema\Postmaclone\FactoryDatasetConfig;
use Ngramx\Postmaclone\Backup\BackupFreshnessChecker;
use Ngramx\Postmaclone\Exception\PostmacloneException;
use PHPUnit\Framework\TestCase;

final class BackupFreshnessCheckerTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('AWS_ACCESS_KEY_ID=test-key');
        putenv('AWS_SECRET_ACCESS_KEY=test-secret');
        putenv('POSTMACLONE_S3_KEY');
        putenv('POSTMACLONE_S3_SECRET');
    }

    protected function tearDown(): void
    {
        putenv('AWS_ACCESS_KEY_ID');
        putenv('AWS_SECRET_ACCESS_KEY');
    }

    public function test_listing_prefix_from_trailing_slash_and_glob(): void
    {
        $checker = new BackupFreshnessChecker();

        self::assertSame('database-backups/all/', $checker->listingPrefix('database-backups/all/'));
        self::assertSame(
            'database-backups/all/',
            $checker->listingPrefix('database-backups/all/*/earl_kendrick_prod.sql.gz')
        );
        self::assertSame(
            'database-backups/all/',
            $checker->listingPrefix('database-backups/all/20260909120000/earl_kendrick_prod.sql.gz')
        );
    }

    public function test_skips_local_backup_sources(): void
    {
        $checker = new BackupFreshnessChecker();
        $checker->assertFresh(new FactoryDatasetConfig(
            name: 'local-only',
            backup: new BackupConfig(source: BackupConfig::SOURCE_LOCAL, path: '/tmp/dump.sql'),
        ));
        $this->addToAssertionCount(1);
    }

    public function test_fresh_object_allows_produce(): void
    {
        $now = new \DateTimeImmutable('2026-09-09T12:00:00+00:00');
        $checker = new BackupFreshnessChecker(
            client: $this->mockClient([
                new Response(200, [], $this->folderListXml()),
                new Response(200, [], $this->objectListXml('2026-09-09T10:00:00.000Z')),
            ]),
            now: $now,
        );

        $checker->assertFresh($this->s3Dataset());
        $this->addToAssertionCount(1);
    }

    public function test_stale_object_fails_produce_with_days_ago(): void
    {
        $now = new \DateTimeImmutable('2026-09-09T12:00:00+00:00');
        $checker = new BackupFreshnessChecker(
            client: $this->mockClient([
                new Response(200, [], $this->folderListXml()),
                new Response(200, [], $this->objectListXml('2026-09-06T10:00:00.000Z')),
            ]),
            now: $now,
        );

        $this->expectException(PostmacloneException::class);
        $this->expectExceptionMessage(
            'Database backups do not appear to be running — last backup file modified 3 days ago'
        );

        $checker->assertFresh($this->s3Dataset());
    }

    public function test_missing_folders_fail_closed(): void
    {
        $checker = new BackupFreshnessChecker(
            client: $this->mockClient([
                new Response(200, [], '<?xml version="1.0"?><ListBucketResult></ListBucketResult>'),
            ]),
            now: new \DateTimeImmutable('2026-09-09T12:00:00+00:00'),
        );

        $this->expectException(PostmacloneException::class);
        $this->expectExceptionMessage(
            'Database backups do not appear to be running — no backup files found'
        );

        $checker->assertFresh($this->s3Dataset());
    }

    private function s3Dataset(): FactoryDatasetConfig
    {
        return new FactoryDatasetConfig(
            name: 'earl-kendrick',
            backup: new BackupConfig(
                source: BackupConfig::SOURCE_S3,
                path: 'spaces://weathered-brook-object-storage/database-backups/all/',
                region: 'lon1',
                endpoint: 'https://lon1.digitaloceanspaces.com',
                file: 'earl_kendrick_prod.sql.gz',
            ),
        );
    }

    private function folderListXml(): string
    {
        return <<<'XML'
        <?xml version="1.0"?>
        <ListBucketResult>
          <CommonPrefixes><Prefix>database-backups/all/20260906100000/</Prefix></CommonPrefixes>
          <CommonPrefixes><Prefix>database-backups/all/20260909100000/</Prefix></CommonPrefixes>
        </ListBucketResult>
        XML;
    }

    private function objectListXml(string $lastModified): string
    {
        return <<<XML
        <?xml version="1.0"?>
        <ListBucketResult>
          <Contents>
            <Key>database-backups/all/20260909100000/earl_kendrick_prod.sql.gz</Key>
            <LastModified>{$lastModified}</LastModified>
          </Contents>
        </ListBucketResult>
        XML;
    }

    /**
     * @param list<Response> $responses
     */
    private function mockClient(array $responses): Client
    {
        return new Client([
            'handler' => HandlerStack::create(new MockHandler($responses)),
            'http_errors' => true,
        ]);
    }
}
