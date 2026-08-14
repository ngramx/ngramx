<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Ngramx\Postmaclone\Backup\S3BackupSource;
use Ngramx\Postmaclone\Backup\S3ObjectLocator;
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

    /**
     * @param list<Response> $responses
     */
    private function source(array $responses): S3BackupSource
    {
        $client = new Client([
            'handler' => HandlerStack::create(new MockHandler($responses)),
            'http_errors' => false,
        ]);

        return new S3BackupSource(
            new S3ObjectLocator(
                bucket: 'anon-backups',
                key: 'earl-kendrick/earl_kendrick_anon.sql',
                region: 'lon1',
                endpoint: 'https://lon1.digitaloceanspaces.com',
                pathStyle: true,
            ),
            $this->dir,
            $client,
        );
    }
}
