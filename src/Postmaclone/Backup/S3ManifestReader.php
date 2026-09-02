<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Backup;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Ngramx\Postmaclone\Exception\PostmacloneException;

/**
 * Read latest.json manifest objects from S3-compatible storage.
 */
class S3ManifestReader
{
    public function __construct(
        private readonly S3ObjectLocator $locator,
        private readonly ?S3Credentials $credentials = null,
        private readonly ?Client $client = null,
        private readonly ?S3SigV4Signer $signer = null,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(): ?array
    {
        [$accessKey, $secretKey, $token] = ($this->credentials ?? new S3Credentials())->require();
        $url = $this->objectUrl($this->locator);
        $signer = $this->signer ?? new S3SigV4Signer();
        $headers = $signer->sign('GET', $url, (string) $this->locator->region, $accessKey, $secretKey, $token);
        $client = $this->client ?? new Client(['timeout' => 60, 'http_errors' => false]);

        try {
            $response = $client->request('GET', $url, ['headers' => $headers]);
        } catch (GuzzleException $e) {
            throw new PostmacloneException('Failed to read manifest: ' . $e->getMessage(), 0, $e);
        }

        if ($response->getStatusCode() === 404) {
            return null;
        }
        if ($response->getStatusCode() >= 400) {
            throw new PostmacloneException('Failed to read manifest: HTTP ' . $response->getStatusCode());
        }

        $body = (string) $response->getBody();
        if ($body === '') {
            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new PostmacloneException('Manifest latest.json is not valid JSON');
        }

        return $decoded;
    }

    private function objectUrl(S3ObjectLocator $locator): string
    {
        if ($locator->pathStyle) {
            return rtrim((string) $locator->endpoint, '/') . '/' . rawurlencode((string) $locator->bucket) . '/' . $this->encodeKey($locator->key);
        }

        $host = parse_url((string) $locator->endpoint, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new PostmacloneException('Invalid S3 endpoint for manifest read');
        }

        return 'https://' . rawurlencode((string) $locator->bucket) . '.' . $host . '/' . $this->encodeKey($locator->key);
    }

    private function encodeKey(string $key): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $key)));
    }
}
