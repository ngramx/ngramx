<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Ngramx\Postmaclone\Backup\S3BackupSource;
use Ngramx\Postmaclone\Backup\S3ObjectLocator;
use Ngramx\Postmaclone\Exception\PostmacloneException;
use PHPUnit\Framework\TestCase;

final class S3BackupSourceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        putenv('AWS_ACCESS_KEY_ID=test-key');
        putenv('AWS_SECRET_ACCESS_KEY=test-secret');
        putenv('POSTMACLONE_S3_KEY');
        putenv('POSTMACLONE_S3_SECRET');
        $this->dir = sys_get_temp_dir() . '/pm-s3-src-' . uniqid('', true);
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        putenv('AWS_ACCESS_KEY_ID');
        putenv('AWS_SECRET_ACCESS_KEY');
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function test_get_last_modified_is_used_instead_of_fresh_cache_mtime(): void
    {
        $header = 'Wed, 01 Jan 2020 00:00:00 GMT';
        $remoteMtime = strtotime($header);
        self::assertNotFalse($remoteMtime);

        $source = $this->source([
            new Response(200, ['Last-Modified' => $header], '-- dump --'),
        ]);

        $path = $source->materialize();
        $cacheMtime = filemtime($path);
        self::assertNotFalse($cacheMtime);

        self::assertGreaterThan($remoteMtime + 86400, $cacheMtime);
        self::assertSame($remoteMtime, $source->lastModified());
    }

    public function test_head_last_modified_without_downloading(): void
    {
        $header = 'Wed, 01 Jan 2020 00:00:00 GMT';
        $remoteMtime = strtotime($header);
        self::assertNotFalse($remoteMtime);

        $source = $this->source([
            new Response(200, [
                'Last-Modified' => $header,
                'Content-Length' => '12',
            ], ''),
        ]);

        self::assertSame($remoteMtime, $source->lastModified());
        self::assertSame([], glob($this->dir . '/*') ?: []);
    }

    public function test_missing_last_modified_returns_null(): void
    {
        $source = $this->source([
            new Response(200, ['Content-Length' => '12'], ''),
        ]);

        self::assertNull($source->lastModified());
    }

    public function test_non_200_head_throws_instead_of_returning_null(): void
    {
        $source = $this->source([
            new Response(404),
        ]);

        $this->expectException(PostmacloneException::class);
        $this->expectExceptionMessage('S3 HEAD failed with HTTP 404');

        $source->lastModified();
    }

    public function test_head_network_error_throws_instead_of_returning_null(): void
    {
        $source = $this->source([
            new ConnectException('Connection refused', new Request('HEAD', 'https://lon1.digitaloceanspaces.com')),
        ]);

        $this->expectException(PostmacloneException::class);
        $this->expectExceptionMessage('S3 HEAD failed: Connection refused');

        $source->lastModified();
    }

    public function test_resolve_failure_throws_instead_of_returning_null(): void
    {
        $source = $this->source([], 'database-backups/all/');

        $this->expectException(PostmacloneException::class);
        $this->expectExceptionMessage('no dump filename was given');

        $source->lastModified();
    }

    /**
     * @param list<Response|\Throwable> $responses
     */
    private function source(array $responses, string $key = 'earl-kendrick/earl_kendrick_anon.sql'): S3BackupSource
    {
        $client = new Client([
            'handler' => HandlerStack::create(new MockHandler($responses)),
            'http_errors' => false,
        ]);

        return new S3BackupSource(
            new S3ObjectLocator(
                bucket: 'anon-backups',
                key: $key,
                region: 'lon1',
                endpoint: 'https://lon1.digitaloceanspaces.com',
                pathStyle: true,
            ),
            $this->dir,
            $client,
        );
    }
}
