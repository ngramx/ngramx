<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Ngramx\Postmaclone\Backup\S3KeyResolver;
use Ngramx\Postmaclone\Backup\S3ObjectLocator;
use Ngramx\Postmaclone\Exception\PostmacloneException;
use PHPUnit\Framework\TestCase;

final class S3KeyResolverTest extends TestCase
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

    public function testConcreteKeyPassthrough(): void
    {
        $locator = new S3ObjectLocator(
            bucket: 'weathered-brook-object-storage',
            key: 'database-backups/all/20260802000004/earl_kendrick_prod.sql.gz',
            region: 'lon1',
            endpoint: 'https://lon1.digitaloceanspaces.com',
            pathStyle: true,
        );

        $resolved = (new S3KeyResolver($locator))->resolve();

        self::assertSame($locator->key, $resolved->key);
    }

    public function testTrailingSlashWithoutFileFails(): void
    {
        $locator = new S3ObjectLocator(
            bucket: 'weathered-brook-object-storage',
            key: 'database-backups/all/',
            region: 'lon1',
            endpoint: 'https://lon1.digitaloceanspaces.com',
            pathStyle: true,
        );

        $this->expectException(PostmacloneException::class);
        $this->expectExceptionMessage('no dump filename was given');

        (new S3KeyResolver($locator))->resolve();
    }

    public function testTrailingSlashWithFilePicksNewestFolder(): void
    {
        $listFolders = <<<'XML'
        <?xml version="1.0"?>
        <ListBucketResult>
          <CommonPrefixes><Prefix>database-backups/all/20260801000002/</Prefix></CommonPrefixes>
          <CommonPrefixes><Prefix>database-backups/all/20260802000004/</Prefix></CommonPrefixes>
        </ListBucketResult>
        XML;

        $listObject = <<<'XML'
        <?xml version="1.0"?>
        <ListBucketResult>
          <Contents><Key>database-backups/all/20260802000004/earl_kendrick_prod.sql.gz</Key></Contents>
        </ListBucketResult>
        XML;

        $client = $this->mockClient([
            new Response(200, [], $listFolders),
            new Response(200, [], $listObject),
        ]);

        $locator = new S3ObjectLocator(
            bucket: 'weathered-brook-object-storage',
            key: 'database-backups/all/',
            region: 'lon1',
            endpoint: 'https://lon1.digitaloceanspaces.com',
            pathStyle: true,
        );

        $resolved = (new S3KeyResolver($locator, $client, file: 'earl_kendrick_prod.sql.gz'))->resolve();

        self::assertSame(
            'database-backups/all/20260802000004/earl_kendrick_prod.sql.gz',
            $resolved->key
        );
    }

    public function testGlobPathWithFilename(): void
    {
        $listFolders = <<<'XML'
        <?xml version="1.0"?>
        <ListBucketResult>
          <CommonPrefixes><Prefix>database-backups/all/20260802000004/</Prefix></CommonPrefixes>
        </ListBucketResult>
        XML;

        $listObject = <<<'XML'
        <?xml version="1.0"?>
        <ListBucketResult>
          <Contents><Key>database-backups/all/20260802000004/earl_kendrick_prod.sql.gz</Key></Contents>
        </ListBucketResult>
        XML;

        $client = $this->mockClient([
            new Response(200, [], $listFolders),
            new Response(200, [], $listObject),
        ]);

        $locator = new S3ObjectLocator(
            bucket: 'weathered-brook-object-storage',
            key: 'database-backups/all/*/earl_kendrick_prod.sql.gz',
            region: 'lon1',
            endpoint: 'https://lon1.digitaloceanspaces.com',
            pathStyle: true,
        );

        $resolved = (new S3KeyResolver($locator, $client))->resolve();

        self::assertSame(
            'database-backups/all/20260802000004/earl_kendrick_prod.sql.gz',
            $resolved->key
        );
    }

    public function testMissingObjectFails(): void
    {
        $listFolders = <<<'XML'
        <?xml version="1.0"?>
        <ListBucketResult>
          <CommonPrefixes><Prefix>database-backups/all/20260802000004/</Prefix></CommonPrefixes>
        </ListBucketResult>
        XML;

        $empty = <<<'XML'
        <?xml version="1.0"?>
        <ListBucketResult></ListBucketResult>
        XML;

        $client = $this->mockClient([
            new Response(200, [], $listFolders),
            new Response(200, [], $empty),
        ]);

        $locator = new S3ObjectLocator(
            bucket: 'weathered-brook-object-storage',
            key: 'database-backups/all/',
            region: 'lon1',
            endpoint: 'https://lon1.digitaloceanspaces.com',
            pathStyle: true,
        );

        $this->expectException(PostmacloneException::class);
        $this->expectExceptionMessage('Backup object not found');

        (new S3KeyResolver($locator, $client, file: 'missing_prod.sql.gz'))->resolve();
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
