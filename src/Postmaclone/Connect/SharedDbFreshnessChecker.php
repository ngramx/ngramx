<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Connect;

use Ngramx\Config\Schema\Postmaclone\PrebuiltConfig;
use Ngramx\Postmaclone\Backup\S3BackupSource;
use Ngramx\Postmaclone\Backup\S3Credentials;
use Ngramx\Postmaclone\Backup\S3ObjectLocator;
use Ngramx\Postmaclone\Exception\PostmacloneException;

/**
 * Verify shared hosted DB freshness via factory latest.json when prebuilt is configured.
 */
final class SharedDbFreshnessChecker
{
    /**
     * @return list<string> warnings (non-blocking)
     */
    public function check(?PrebuiltConfig $prebuilt, ?int $maxAgeHours, bool $strict): array
    {
        if ($maxAgeHours === null || $maxAgeHours <= 0) {
            return [];
        }

        if ($prebuilt === null || $prebuilt->path === null || $prebuilt->path === '') {
            return [
                'shared.max_age_hours is set but postmaclone.prebuilt.path is missing — cannot verify freshness',
            ];
        }

        $manifest = $this->downloadManifest($prebuilt);
        if ($manifest === null) {
            $message = 'Could not read prebuilt latest.json to verify shared.max_age_hours';
            if ($strict) {
                throw new PostmacloneException($message);
            }

            return [$message];
        }

        $createdAt = $manifest['created_at'] ?? null;
        if (!is_string($createdAt) || $createdAt === '') {
            $message = 'prebuilt latest.json has no created_at — cannot verify shared.max_age_hours';
            if ($strict) {
                throw new PostmacloneException($message);
            }

            return [$message];
        }

        try {
            $created = new \DateTimeImmutable($createdAt);
        } catch (\Exception) {
            $message = 'prebuilt latest.json created_at is not a valid timestamp';
            if ($strict) {
                throw new PostmacloneException($message);
            }

            return [$message];
        }

        $ageHours = (time() - $created->getTimestamp()) / 3600;
        if ($ageHours > $maxAgeHours) {
            $message = sprintf(
                'Shared hosted DB may be stale (latest.json created_at is %.1f hours old; max_age_hours=%d)',
                $ageHours,
                $maxAgeHours,
            );
            if ($strict) {
                throw new PostmacloneException($message);
            }

            return [$message];
        }

        return [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function downloadManifest(PrebuiltConfig $prebuilt): ?array
    {
        try {
            $locator = S3ObjectLocator::parse(
                rtrim((string) $prebuilt->path, '/') . '/latest.json',
                $prebuilt->region,
                $prebuilt->endpoint,
                $prebuilt->pathStyle,
            );
            $cacheDir = sys_get_temp_dir() . '/ngramx-connect-' . uniqid('', true);
            if (!is_dir($cacheDir) && !mkdir($cacheDir, 0700, true) && !is_dir($cacheDir)) {
                return null;
            }

            $source = new S3BackupSource(
                $locator,
                $cacheDir,
                credentials: new S3Credentials($prebuilt->credentials),
            );
            $path = $source->materialize();
            $raw = file_get_contents($path);
            $source->cleanup(false);
            @rmdir($cacheDir);
            if ($raw === false) {
                return null;
            }
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
