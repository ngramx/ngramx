<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
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
        $log = new RequestLog();
        $this->uploader($log, [new Response(200)], threshold: 100, partSize: 50)->putFile($path);

        self::assertCount(1, $log->requests);
        $request = $log->requests[0];
        self::assertSame('PUT', $request->getMethod());
        self::assertSame('/weathered-brook-anonymized-backups/hydra/hydra_anon.sql.gz', $request->getUri()->getPath());
        self::assertSame('', $request->getUri()->getQuery());
        @unlink($path);
    }

    public function test_large_file_uses_multipart_and_keeps_gzip_parts(): void
    {
        $path = $this->writeTemp(str_repeat('A', 120));
        $log = new RequestLog();
        $this->uploader($log, [
            new Response(200, [], '<InitiateMultipartUploadResult><UploadId>abc-123</UploadId></InitiateMultipartUploadResult>'),
            new Response(200, ['ETag' => '"etag-1"']),
            new Response(200, ['ETag' => '"etag-2"']),
            new Response(200, ['ETag' => '"etag-3"']),
            new Response(200),
        ], threshold: 100, partSize: 50)->putFile($path);

        self::assertCount(5, $log->requests);
        self::assertSame('POST', $log->requests[0]->getMethod());
        self::assertSame('uploads=', $log->requests[0]->getUri()->getQuery());

        self::assertSame('PUT', $log->requests[1]->getMethod());
        self::assertStringContainsString('partNumber=1', $log->requests[1]->getUri()->getQuery());
        self::assertStringContainsString('uploadId=abc-123', $log->requests[1]->getUri()->getQuery());
        self::assertSame(str_repeat('A', 50), (string) $log->requests[1]->getBody());

        self::assertSame('PUT', $log->requests[2]->getMethod());
        self::assertStringContainsString('partNumber=2', $log->requests[2]->getUri()->getQuery());

        self::assertSame('PUT', $log->requests[3]->getMethod());
        self::assertStringContainsString('partNumber=3', $log->requests[3]->getUri()->getQuery());
        self::assertSame(str_repeat('A', 20), (string) $log->requests[3]->getBody());

        $complete = $log->requests[4];
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
        $log = new RequestLog();
        $uploader = $this->uploader($log, [
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

        $last = $log->requests[array_key_last($log->requests)];
        self::assertSame('DELETE', $last->getMethod());
        self::assertSame('uploadId=abort-me', $last->getUri()->getQuery());
        @unlink($path);
    }

    /**
     * @param list<Response> $responses
     */
    private function uploader(RequestLog $log, array $responses, int $threshold, int $partSize): S3ObjectUploader
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(static function (callable $handler) use ($log): callable {
            return static function (RequestInterface $request, array $options) use ($handler, $log) {
                $log->requests[] = $request;

                return $handler($request, $options);
            };
        });

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

    private function writeTemp(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ngramx-s3-put-');
        self::assertNotFalse($path);
        file_put_contents($path, $contents);

        return $path;
    }
}

final class RequestLog
{
    /** @var list<RequestInterface> */
    public array $requests = [];
}
