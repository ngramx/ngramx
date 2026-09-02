<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone;

use Ngramx\Config\Schema\Postmaclone\PublishConfig;
use Ngramx\Postmaclone\Backup\S3Credentials;
use Ngramx\Postmaclone\Backup\S3ManifestReader;
use Ngramx\Postmaclone\Backup\S3ObjectLocator;
use Ngramx\Postmaclone\Backup\S3ObjectUploader;
use Ngramx\Postmaclone\Exception\PostmacloneException;

/**
 * Factory-wide password rotation timestamps keyed by op:// credential reference.
 *
 * Stored at {anonymized-bucket}/_postmaclone/credential-rotations.json so all datasets
 * sharing postmaclone-anon (or any credential) rotate on one schedule.
 */
final class CredentialRotationStateStore
{
    public const STATE_OBJECT_KEY = '_postmaclone/credential-rotations.json';

    public function __construct(
        private readonly S3ObjectLocator $locator,
        private readonly S3Credentials $credentials,
        private readonly ?S3ManifestReader $reader = null,
        private readonly ?S3ObjectUploader $uploader = null,
    ) {
    }

    public static function forPublish(PublishConfig $publish): self
    {
        if ($publish->path === null || $publish->path === '') {
            throw new PostmacloneException('publish.path is required for credential rotation state');
        }
        if ($publish->credentials === null) {
            throw new PostmacloneException('publish.credentials are required for credential rotation state');
        }

        $bucketLocator = S3ObjectLocator::parse(
            rtrim($publish->path, '/') . '/',
            $publish->region,
            $publish->endpoint,
            $publish->pathStyle,
        );

        return new self(
            new S3ObjectLocator(
                $bucketLocator->bucket,
                self::STATE_OBJECT_KEY,
                $publish->region,
                $publish->endpoint,
                $bucketLocator->pathStyle,
            ),
            new S3Credentials($publish->credentials),
        );
    }

    public function lastRotatedAt(string $credentialKey): ?string
    {
        $state = $this->readState();
        $value = $state['credentials'][$credentialKey] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function recordRotatedAt(string $credentialKey, string $rotatedAt): void
    {
        $state = $this->readState();
        $state['credentials'][$credentialKey] = $rotatedAt;

        ($this->uploader ?? new S3ObjectUploader($this->locator, $this->credentials))
            ->putBody((string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array{version: int, credentials: array<string, string>}
     */
    private function readState(): array
    {
        $manifest = ($this->reader ?? new S3ManifestReader($this->locator, $this->credentials))->read();
        if ($manifest === null) {
            return ['version' => 1, 'credentials' => []];
        }

        $credentials = $manifest['credentials'] ?? [];
        if (!is_array($credentials)) {
            $credentials = [];
        }

        $normalized = [];
        foreach ($credentials as $key => $value) {
            if (is_string($key) && is_string($value) && $value !== '') {
                $normalized[$key] = $value;
            }
        }

        return [
            'version' => is_int($manifest['version'] ?? null) ? $manifest['version'] : 1,
            'credentials' => $normalized,
        ];
    }
}
