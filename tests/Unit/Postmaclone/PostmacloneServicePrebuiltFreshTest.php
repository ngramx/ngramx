<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Ngramx\Postmaclone\Backup\LocalBackupSource;
use Ngramx\Postmaclone\Backup\S3BackupSource;
use Ngramx\Postmaclone\Backup\S3ObjectLocator;
use Ngramx\Postmaclone\Exception\PostmacloneException;
use Ngramx\Postmaclone\PostmacloneService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class PostmacloneServicePrebuiltFreshTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        putenv('AWS_ACCESS_KEY_ID=test-key');
        putenv('AWS_SECRET_ACCESS_KEY=test-secret');
        putenv('POSTMACLONE_S3_KEY');
        putenv('POSTMACLONE_S3_SECRET');
        $this->dir = sys_get_temp_dir() . '/pm-fresh-' . uniqid('', true);
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

    public function test_rejects_stale_s3_object_from_head_without_download(): void
    {
        $source = $this->s3Source([
            new Response(200, ['Last-Modified' => 'Wed, 01 Jan 2020 00:00:00 GMT'], ''),
        ]);

        try {
            $this->assertFresh($source, 36);
            self::fail('expected stale prebuilt to be rejected');
        } catch (PostmacloneException $e) {
            self::assertStringContainsString('older than max_age_hours (36)', $e->getMessage());
        }

        self::assertSame([], glob($this->dir . '/*') ?: []);
    }

    public function test_rejects_stale_remote_object_even_when_local_cache_is_fresh(): void
    {
        $source = $this->s3Source([
            new Response(200, ['Last-Modified' => 'Wed, 01 Jan 2020 00:00:00 GMT'], '-- dump --'),
        ]);
        $path = $source->materialize();
        $cacheMtime = filemtime($path);
        self::assertNotFalse($cacheMtime);
        self::assertGreaterThan(time() - 60, $cacheMtime);

        $this->expectException(PostmacloneException::class);
        $this->expectExceptionMessage('older than max_age_hours (36)');

        $this->assertFresh($source, 36);
    }

    public function test_rejects_when_remote_age_cannot_be_determined(): void
    {
        $source = $this->s3Source([
            new Response(200, ['Content-Length' => '8'], ''),
        ]);

        $this->expectException(PostmacloneException::class);
        $this->expectExceptionMessage('Could not determine Prebuilt artifact age');

        $this->assertFresh($source, 36);
    }

    public function test_missing_local_prebuilt_surfaces_path_error_not_unknown_age(): void
    {
        $path = $this->dir . '/missing.sql';

        try {
            $this->assertFresh(new LocalBackupSource($path), 36);
            self::fail('expected missing prebuilt to surface the path error');
        } catch (PostmacloneException $e) {
            self::assertStringContainsString("Dump file not found: {$path}", $e->getMessage());
            self::assertStringNotContainsString('Could not determine Prebuilt artifact age', $e->getMessage());
            self::assertStringNotContainsString('factory produce', $e->getMessage());
        }
    }

    public function test_s3_head_failure_surfaces_http_error_not_unknown_age(): void
    {
        $source = $this->s3Source([
            new Response(403),
        ]);

        try {
            $this->assertFresh($source, 36);
            self::fail('expected S3 HEAD failure to surface the HTTP error');
        } catch (PostmacloneException $e) {
            self::assertStringContainsString('S3 HEAD failed with HTTP 403', $e->getMessage());
            self::assertStringNotContainsString('Could not determine Prebuilt artifact age', $e->getMessage());
            self::assertStringNotContainsString('factory produce', $e->getMessage());
        }
    }

    public function test_s3_network_error_surfaces_head_failure_not_unknown_age(): void
    {
        $source = $this->s3Source([
            new ConnectException('Connection refused', new Request('HEAD', 'https://lon1.digitaloceanspaces.com')),
        ]);

        try {
            $this->assertFresh($source, 36);
            self::fail('expected S3 network error to surface the HEAD failure');
        } catch (PostmacloneException $e) {
            self::assertStringContainsString('S3 HEAD failed: Connection refused', $e->getMessage());
            self::assertStringNotContainsString('Could not determine Prebuilt artifact age', $e->getMessage());
        }
    }

    public function test_s3_resolve_failure_surfaces_resolver_error_not_unknown_age(): void
    {
        $source = $this->s3Source([], 'database-backups/all/');

        try {
            $this->assertFresh($source, 36);
            self::fail('expected S3 resolve failure to surface the resolver error');
        } catch (PostmacloneException $e) {
            self::assertStringContainsString('no dump filename was given', $e->getMessage());
            self::assertStringNotContainsString('Could not determine Prebuilt artifact age', $e->getMessage());
        }
    }

    public function test_accepts_fresh_local_prebuilt(): void
    {
        $path = $this->dir . '/anon.sql';
        file_put_contents($path, '-- dump --');
        touch($path, time() - 3600);

        $this->assertFresh(new LocalBackupSource($path), 36);
        $this->addToAssertionCount(1);
    }

    public function test_rejects_stale_local_prebuilt(): void
    {
        $path = $this->dir . '/anon.sql';
        file_put_contents($path, '-- dump --');
        touch($path, time() - 48 * 3600);

        $this->expectException(PostmacloneException::class);
        $this->expectExceptionMessage('older than max_age_hours (36)');

        $this->assertFresh(new LocalBackupSource($path), 36);
    }

    /**
     * @param list<Response|\Throwable> $responses
     */
    private function s3Source(array $responses, string $key = 'earl-kendrick/earl_kendrick_anon.sql'): S3BackupSource
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

    private function assertFresh(S3BackupSource|LocalBackupSource $source, int $maxAgeHours): void
    {
        $method = new ReflectionMethod(PostmacloneService::class, 'assertPrebuiltFresh');
        $method->setAccessible(true);
        $method->invoke(new PostmacloneService(), $source, $maxAgeHours);
    }
}
