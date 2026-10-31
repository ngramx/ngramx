<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Backup;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Ngramx\Postmaclone\Exception\PostmacloneException;
use Psr\Http\Message\ResponseInterface;

/**
 * PUT objects to S3-compatible storage (anonymized artifact publish).
 *
 * DigitalOcean Spaces rejects a single PUT over 5 GB (EntityTooLarge).
 * Files above the multipart threshold use the S3 multipart API.
 */
class S3ObjectUploader
{
    public const DEFAULT_MULTIPART_THRESHOLD_BYTES = 4_294_967_296;

    public const DEFAULT_PART_SIZE_BYTES = 67_108_864;

    public function __construct(
        private readonly S3ObjectLocator $locator,
        private readonly ?S3Credentials $credentials = null,
        private readonly ?Client $client = null,
        private readonly ?S3SigV4Signer $signer = null,
        private readonly int $multipartThresholdBytes = self::DEFAULT_MULTIPART_THRESHOLD_BYTES,
        private readonly int $partSizeBytes = self::DEFAULT_PART_SIZE_BYTES,
    ) {
    }

    public function putFile(string $localPath, ?string $contentType = 'application/octet-stream'): void
    {
        if (!is_file($localPath)) {
            throw new PostmacloneException("Upload source missing: {$localPath}");
        }

        $size = filesize($localPath);
        if ($size !== false && $size > $this->multipartThresholdBytes) {
            $this->putMultipart($localPath, $contentType, $size);

            return;
        }

        $this->put($localPath, $contentType, true);
    }

    public function putBody(string $body, ?string $contentType = 'application/json'): void
    {
        $tmp = sys_get_temp_dir() . '/ngramx-put-' . bin2hex(random_bytes(8));
        if (file_put_contents($tmp, $body) === false) {
            throw new PostmacloneException('Failed to write temporary upload body');
        }
        try {
            $this->put($tmp, $contentType, false);
        } finally {
            @unlink($tmp);
        }
    }

    private function put(string $localPath, ?string $contentType, bool $streamFile): void
    {
        $url = $this->objectUrl($this->locator);
        $body = $streamFile ? fopen($localPath, 'rb') : (string) file_get_contents($localPath);
        if ($body === false) {
            throw new PostmacloneException("Failed to read upload source: {$localPath}");
        }

        try {
            $this->signedRequest('PUT', $url, $body, $contentType, S3SigV4Signer::UNSIGNED_PAYLOAD);
        } finally {
            if (is_resource($body)) {
                fclose($body);
            }
        }
    }

    private function putMultipart(string $localPath, ?string $contentType, int $size): void
    {
        $uploadId = $this->initiateMultipart($contentType);
        $parts = [];
        $in = fopen($localPath, 'rb');
        if ($in === false) {
            $this->abortMultipart($uploadId);
            throw new PostmacloneException("Failed to read upload source: {$localPath}");
        }

        try {
            $partNumber = 1;
            $remaining = $size;
            while ($remaining > 0) {
                $length = min($this->partSizeBytes, $remaining);
                if ($length < 1) {
                    break;
                }
                $chunk = fread($in, $length);
                if (!is_string($chunk) || $chunk === '') {
                    throw new PostmacloneException("Failed to read upload part {$partNumber} of {$localPath}");
                }
                $etag = $this->uploadPart($uploadId, $partNumber, $chunk, $contentType);
                $parts[] = [$partNumber, $etag];
                $remaining -= strlen($chunk);
                $partNumber++;
            }
            $this->completeMultipart($uploadId, $parts);
        } catch (\Throwable $e) {
            $this->abortMultipart($uploadId);
            if ($e instanceof PostmacloneException) {
                throw $e;
            }
            throw new PostmacloneException('S3 multipart upload failed: ' . $e->getMessage(), 0, $e);
        } finally {
            fclose($in);
        }
    }

    private function initiateMultipart(?string $contentType): string
    {
        $url = $this->objectUrl($this->locator, ['uploads' => '']);
        $response = $this->signedRequest('POST', $url, '', $contentType, hash('sha256', ''));
        $xml = (string) $response->getBody();
        if (preg_match('/<UploadId>([^<]+)<\/UploadId>/', $xml, $match) !== 1) {
            throw new PostmacloneException('S3 multipart initiate did not return an UploadId');
        }

        return html_entity_decode($match[1], ENT_QUOTES | ENT_XML1);
    }

    private function uploadPart(string $uploadId, int $partNumber, string $chunk, ?string $contentType): string
    {
        $url = $this->objectUrl($this->locator, [
            'partNumber' => (string) $partNumber,
            'uploadId' => $uploadId,
        ]);
        $response = $this->signedRequest(
            'PUT',
            $url,
            $chunk,
            $contentType,
            S3SigV4Signer::UNSIGNED_PAYLOAD,
        );
        $etag = trim($response->getHeaderLine('ETag'));
        if ($etag === '') {
            throw new PostmacloneException("S3 multipart part {$partNumber} did not return an ETag");
        }

        return $etag;
    }

    /**
     * @param list<array{0: int, 1: string}> $parts
     */
    private function completeMultipart(string $uploadId, array $parts): void
    {
        $xml = '<CompleteMultipartUpload>';
        foreach ($parts as [$number, $etag]) {
            $xml .= '<Part><PartNumber>' . $number . '</PartNumber><ETag>'
                . htmlspecialchars($etag, ENT_XML1) . '</ETag></Part>';
        }
        $xml .= '</CompleteMultipartUpload>';

        $url = $this->objectUrl($this->locator, ['uploadId' => $uploadId]);
        $this->signedRequest('POST', $url, $xml, 'application/xml', hash('sha256', $xml));
    }

    private function abortMultipart(string $uploadId): void
    {
        try {
            $url = $this->objectUrl($this->locator, ['uploadId' => $uploadId]);
            $this->signedRequest('DELETE', $url, '', null, hash('sha256', ''));
        } catch (\Throwable) {
            // best-effort
        }
    }

    /**
     * @param resource|string $body
     */
    private function signedRequest(
        string $method,
        string $url,
        $body,
        ?string $contentType,
        string $payloadHash,
    ): ResponseInterface {
        [$accessKey, $secretKey, $token] = ($this->credentials ?? new S3Credentials())->require();
        $signer = $this->signer ?? new S3SigV4Signer();
        $headers = $signer->sign(
            $method,
            $url,
            (string) $this->locator->region,
            $accessKey,
            $secretKey,
            $token,
            payloadHash: $payloadHash,
            contentType: $contentType,
        );

        $client = $this->client ?? new Client(['timeout' => 3600, 'http_errors' => true]);
        try {
            $response = $client->request($method, $url, [
                'headers' => $headers,
                'body' => $body,
            ]);
        } catch (GuzzleException $e) {
            throw new PostmacloneException('S3 upload failed: ' . $e->getMessage(), 0, $e);
        }

        if ($response->getStatusCode() >= 400) {
            throw new PostmacloneException('S3 upload failed with HTTP ' . $response->getStatusCode());
        }

        return $response;
    }

    /**
     * @param array<string, string> $query
     */
    private function objectUrl(S3ObjectLocator $locator, array $query = []): string
    {
        $bucket = $locator->bucket;
        $key = implode('/', array_map('rawurlencode', explode('/', $locator->key)));

        if ($locator->endpoint) {
            $base = rtrim($locator->endpoint, '/');
            if ($locator->pathStyle) {
                $url = "{$base}/{$bucket}/{$key}";
            } else {
                $host = parse_url($base, PHP_URL_HOST);
                $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
                $url = "{$scheme}://{$bucket}.{$host}/{$key}";
            }
        } else {
            $url = "https://{$bucket}.s3.{$locator->region}.amazonaws.com/{$key}";
        }

        if ($query === []) {
            return $url;
        }

        $pairs = [];
        foreach ($query as $name => $value) {
            $pairs[] = rawurlencode($name) . '=' . rawurlencode($value);
        }

        return $url . '?' . implode('&', $pairs);
    }
}
