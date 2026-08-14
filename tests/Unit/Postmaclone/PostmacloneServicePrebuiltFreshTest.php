<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
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
     * @param list<Response> $responses
     */
    private function s3Source(array $responses): S3BackupSource
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

    private function assertFresh(S3BackupSource|LocalBackupSource $source, int $maxAgeHours): void
    {
        $method = new ReflectionMethod(PostmacloneService::class, 'assertPrebuiltFresh');
        $method->setAccessible(true);
        $method->invoke(new PostmacloneService(), $source, $maxAgeHours);
    }
}
