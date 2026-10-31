<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Ngramx\Postmaclone\Backup\S3ObjectLocator;
use Ngramx\Postmaclone\Backup\S3ObjectUploader;
use Ngramx\Postmaclone\Exception\PostmacloneException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class S3ObjectUploaderTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('AWS_ACCESS_KEY_ID=test-key');
        putenv('AWS_SECRET_ACCESS_KEY=test-secret');
    }

    protected function tearDown(): void
    {
        putenv('AWS_ACCESS_KEY_ID');
        putenv('AWS_SECRET_ACCESS_KEY');
    }

    public function test_small_file_uses_single_put(): void
    {
        $path = $this->writeTemp('small-payload');
        $history = [];
        $this->uploader($history, [new Response(200)], threshold: 100, partSize: 50)->putFile($path);

        self::assertCount(1, $history);
        $request = $this->requestAt($history, 0);
        self::assertSame('PUT', $request->getMethod());
        self::assertSame('/weathered-brook-anonymized-backups/hydra/hydra_anon.sql.gz', $request->getUri()->getPath());
        self::assertSame('', $request->getUri()->getQuery());
        @unlink($path);
    }

    public function test_large_file_uses_multipart_and_keeps_gzip_parts(): void
    {
        $path = $this->writeTemp(str_repeat('A', 120));
        $history = [];
        $this->uploader($history, [
            new Response(200, [], '<InitiateMultipartUploadResult><UploadId>abc-123</UploadId></InitiateMultipartUploadResult>'),
            new Response(200, ['ETag' => '"etag-1"']),
            new Response(200, ['ETag' => '"etag-2"']),
            new Response(200, ['ETag' => '"etag-3"']),
            new Response(200),
        ], threshold: 100, partSize: 50)->putFile($path);

        self::assertCount(5, $history);
        self::assertSame('POST', $this->requestAt($history, 0)->getMethod());
        self::assertSame('uploads=', $this->requestAt($history, 0)->getUri()->getQuery());

        self::assertSame('PUT', $this->requestAt($history, 1)->getMethod());
        self::assertStringContainsString('partNumber=1', $this->requestAt($history, 1)->getUri()->getQuery());
        self::assertStringContainsString('uploadId=abc-123', $this->requestAt($history, 1)->getUri()->getQuery());
        self::assertSame(str_repeat('A', 50), (string) $this->requestAt($history, 1)->getBody());

        self::assertSame('PUT', $this->requestAt($history, 2)->getMethod());
        self::assertStringContainsString('partNumber=2', $this->requestAt($history, 2)->getUri()->getQuery());

        self::assertSame('PUT', $this->requestAt($history, 3)->getMethod());
        self::assertStringContainsString('partNumber=3', $this->requestAt($history, 3)->getUri()->getQuery());
        self::assertSame(str_repeat('A', 20), (string) $this->requestAt($history, 3)->getBody());

        $complete = $this->requestAt($history, 4);
        self::assertSame('POST', $complete->getMethod());
        self::assertSame('uploadId=abc-123', $complete->getUri()->getQuery());
        $xml = (string) $complete->getBody();
        self::assertStringContainsString('<PartNumber>1</PartNumber>', $xml);
        self::assertStringContainsString('"etag-1"', $xml);
        self::assertStringContainsString('<PartNumber>3</PartNumber>', $xml);
        @unlink($path);
    }

    public function test_multipart_failure_aborts_upload(): void
    {
        $path = $this->writeTemp(str_repeat('B', 120));
        $history = [];
        $uploader = $this->uploader($history, [
            new Response(200, [], '<InitiateMultipartUploadResult><UploadId>abort-me</UploadId></InitiateMultipartUploadResult>'),
            new Response(200, ['ETag' => '"etag-1"']),
            new Response(500, [], 'nope'),
            new Response(204),
        ], threshold: 100, partSize: 50);

        try {
            $uploader->putFile($path);
            self::fail('Expected multipart upload to fail');
        } catch (PostmacloneException $e) {
            self::assertStringContainsString('S3 upload failed', $e->getMessage());
        }

        $last = $this->requestAt($history, count($history) - 1);
        self::assertSame('DELETE', $last->getMethod());
        self::assertSame('uploadId=abort-me', $last->getUri()->getQuery());
        @unlink($path);
    }

    /**
     * @param array<int, mixed> $history
     * @param list<Response> $responses
     */
    private function uploader(array &$history, array $responses, int $threshold, int $partSize): S3ObjectUploader
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        /** @phpstan-ignore paramOut.type (Guzzle history container is array|ArrayAccess) */
        $stack->push(Middleware::history($history));

        return new S3ObjectUploader(
            new S3ObjectLocator(
                'weathered-brook-anonymized-backups',
                'hydra/hydra_anon.sql.gz',
                'lon1',
                'https://lon1.digitaloceanspaces.com',
                true,
            ),
            client: new Client(['handler' => $stack, 'http_errors' => true]),
            multipartThresholdBytes: $threshold,
            partSizeBytes: $partSize,
        );
    }

    /**
     * @param array<int, mixed> $history
     */
    private function requestAt(array $history, int $index): RequestInterface
    {
        $entry = $history[$index] ?? null;
        self::assertIsArray($entry);
        $request = $entry['request'] ?? null;
        self::assertInstanceOf(RequestInterface::class, $request);

        return $request;
    }

    private function writeTemp(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ngramx-s3-put-');
        self::assertNotFalse($path);
        file_put_contents($path, $contents);

        return $path;
    }
}
