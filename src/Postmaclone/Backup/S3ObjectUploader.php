<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Backup;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Ngramx\Postmaclone\Exception\PostmacloneException;

/**
 * PUT objects to S3-compatible storage (anonymized artifact publish).
 */
class S3ObjectUploader
{
    public function __construct(
        private readonly S3ObjectLocator $locator,
        private readonly ?S3Credentials $credentials = null,
        private readonly ?Client $client = null,
        private readonly ?S3SigV4Signer $signer = null,
    ) {
    }

    public function putFile(string $localPath, ?string $contentType = 'application/octet-stream'): void
    {
        if (!is_file($localPath)) {
            throw new PostmacloneException("Upload source missing: {$localPath}");
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
        [$accessKey, $secretKey, $token] = ($this->credentials ?? new S3Credentials())->require();
        $url = $this->objectUrl($this->locator);
        $signer = $this->signer ?? new S3SigV4Signer();
        $headers = $signer->sign(
            'PUT',
            $url,
            (string) $this->locator->region,
            $accessKey,
            $secretKey,
            $token,
            payloadHash: S3SigV4Signer::UNSIGNED_PAYLOAD,
            contentType: $contentType,
        );

        $client = $this->client ?? new Client(['timeout' => 3600, 'http_errors' => true]);
        $options = ['headers' => $headers];
        if ($streamFile) {
            $options['body'] = fopen($localPath, 'rb');
        } else {
            $options['body'] = (string) file_get_contents($localPath);
        }

        try {
            $response = $client->request('PUT', $url, $options);
        } catch (GuzzleException $e) {
            throw new PostmacloneException('S3 upload failed: ' . $e->getMessage(), 0, $e);
        } finally {
            if (isset($options['body']) && is_resource($options['body'])) {
                fclose($options['body']);
            }
        }

        if ($response->getStatusCode() >= 400) {
            throw new PostmacloneException('S3 upload failed with HTTP ' . $response->getStatusCode());
        }
    }

    private function objectUrl(S3ObjectLocator $locator): string
    {
        $bucket = $locator->bucket;
        $key = implode('/', array_map('rawurlencode', explode('/', $locator->key)));

        if ($locator->endpoint) {
            $base = rtrim($locator->endpoint, '/');
            if ($locator->pathStyle) {
                return "{$base}/{$bucket}/{$key}";
            }

            $host = parse_url($base, PHP_URL_HOST);
            $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';

            return "{$scheme}://{$bucket}.{$host}/{$key}";
        }

        return "https://{$bucket}.s3.{$locator->region}.amazonaws.com/{$key}";
    }
}
