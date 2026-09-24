<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Backup;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Ngramx\Postmaclone\Exception\PostmacloneException;
use Ngramx\Postmaclone\Progress\PercentReporter;
use Psr\Http\Message\ResponseInterface;

class S3BackupSource implements BackupSourceInterface
{
    private ?string $localPath = null;
    private ?S3ObjectLocator $resolvedLocator = null;
    private ?int $lastModified = null;

    /**
     * @var (callable(string): void)|null
     */
    private $onProgress;

    /**
     * @param (callable(string): void)|null $onProgress
     */
    public function __construct(
        private readonly S3ObjectLocator $locator,
        private readonly string $cacheDir,
        private readonly ?Client $client = null,
        private readonly ?S3SigV4Signer $signer = null,
        private readonly ?string $file = null,
        private readonly ?S3Credentials $credentials = null,
        ?callable $onProgress = null,
    ) {
        $this->onProgress = $onProgress;
    }

    public function materialize(): string
    {
        $locator = $this->resolved();
        [$accessKey, $secretKey, $token] = $this->credentials();
        $url = $this->objectUrl($locator);
        $signer = $this->signer ?? new S3SigV4Signer();
        $headers = $signer->sign('GET', $url, (string) $locator->region, $accessKey, $secretKey, $token);

        if (!is_dir($this->cacheDir) && !mkdir($this->cacheDir, 0700, true) && !is_dir($this->cacheDir)) {
            throw new PostmacloneException("Failed to create cache dir: {$this->cacheDir}");
        }

        $hash = hash('sha256', $url);
        $path = rtrim($this->cacheDir, '/') . '/postmaclone-' . substr($hash, 0, 16) . '.dump';
        $this->localPath = $path;

        $client = $this->client ?? new Client(['timeout' => 600, 'http_errors' => true]);
        $reporter = null;
        $onProgress = $this->onProgress;

        try {
            $response = $client->request('GET', $url, [
                'headers' => $headers,
                'sink' => $path,
                'progress' => static function (int|float $dlTotal, int|float $dlNow) use (&$reporter, $onProgress): void {
                    if ($onProgress === null) {
                        return;
                    }
                    $total = (int) $dlTotal;
                    if ($total <= 0) {
                        return;
                    }
                    if ($reporter === null) {
                        $reporter = new PercentReporter($total, 'Downloading dump', $onProgress);
                    }
                    $reporter->set((int) $dlNow);
                },
            ]);
        } catch (GuzzleException $e) {
            throw new PostmacloneException('S3 download failed: ' . $e->getMessage(), 0, $e);
        }

        if ($reporter instanceof PercentReporter) {
            $reporter->finish();
        }

        if ($response->getStatusCode() >= 400) {
            throw new PostmacloneException('S3 download failed with HTTP ' . $response->getStatusCode());
        }

        $this->captureLastModified($response);

        return $path;
    }

    public function probe(): array
    {
        try {
            $locator = $this->resolved();
        } catch (PostmacloneException $e) {
            return ['exists' => false, 'detail' => $e->getMessage()];
        }

        [$accessKey, $secretKey, $token] = $this->credentials();
        $url = $this->objectUrl($locator);
        $signer = $this->signer ?? new S3SigV4Signer();
        $headers = $signer->sign('HEAD', $url, (string) $locator->region, $accessKey, $secretKey, $token);
        $client = $this->client ?? new Client(['timeout' => 30, 'http_errors' => false]);

        try {
            $response = $client->request('HEAD', $url, ['headers' => $headers]);
        } catch (GuzzleException $e) {
            return ['exists' => false, 'detail' => 'S3 HEAD failed: ' . $e->getMessage()];
        }

        if ($response->getStatusCode() === 200) {
            $this->captureLastModified($response);
            $length = $response->getHeaderLine('Content-Length');

            return [
                'exists' => true,
                'size' => $length !== '' ? (int) $length : null,
                'detail' => "s3://{$locator->bucket}/{$locator->key}",
                'modified_at' => $this->lastModified,
            ];
        }

        return ['exists' => false, 'detail' => 'S3 HEAD failed with HTTP ' . $response->getStatusCode()];
    }

    public function lastModified(): ?int
    {
        if ($this->lastModified !== null) {
            return $this->lastModified;
        }

        $probe = $this->probe();
        if (!$probe['exists']) {
            throw new PostmacloneException($probe['detail'] ?? 'S3 object is not available');
        }

        return $this->lastModified;
    }

    private function resolved(): S3ObjectLocator
    {
        if ($this->resolvedLocator !== null) {
            return $this->resolvedLocator;
        }

        $resolver = new S3KeyResolver(
            $this->locator,
            $this->client,
            $this->signer,
            $this->file,
            $this->credentials,
        );
        $this->resolvedLocator = $resolver->resolve();

        return $this->resolvedLocator;
    }

    public function cleanup(bool $keep): void
    {
        if ($keep || $this->localPath === null) {
            return;
        }
        if (is_file($this->localPath)) {
            unlink($this->localPath);
        }
        $ungz = $this->localPath . '.ungz';
        if (is_file($ungz)) {
            unlink($ungz);
        }
    }

    private function captureLastModified(ResponseInterface $response): void
    {
        $header = $response->getHeaderLine('Last-Modified');
        if ($header === '') {
            return;
        }
        $timestamp = strtotime($header);
        if ($timestamp !== false) {
            $this->lastModified = $timestamp;
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string|null}
     */
    private function credentials(): array
    {
        return ($this->credentials ?? new S3Credentials())->require();
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
