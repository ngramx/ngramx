<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Backup;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Ngramx\Config\Schema\Postmaclone\BackupConfig;
use Ngramx\Config\Schema\Postmaclone\FactoryDatasetConfig;
use Ngramx\Postmaclone\Exception\PostmacloneException;

/**
 * Fail produce when the raw Forge/Spaces backup prefix has gone stale.
 *
 * Lists dated folders under the configured prefix, then takes the newest
 * object's Last-Modified. Local dumps are skipped.
 */
final class BackupFreshnessChecker
{
    public const MAX_AGE_SECONDS = 86400;

    public function __construct(
        private readonly ?Client $client = null,
        private readonly ?S3SigV4Signer $signer = null,
        private readonly ?\DateTimeImmutable $now = null,
    ) {
    }

    public function assertFresh(FactoryDatasetConfig $dataset): void
    {
        $backup = $dataset->backup;
        if (!$this->isRemoteObjectStore($backup)) {
            return;
        }
        if ($backup->path === null || $backup->path === '') {
            return;
        }

        $locator = S3ObjectLocator::parse(
            $backup->path,
            $backup->region,
            $backup->endpoint,
            $backup->pathStyle,
        );
        $prefix = $this->listingPrefix($locator->key);
        $folder = $this->newestDatedFolder($locator, $prefix, $backup);
        $lastModified = $this->newestObjectTime($locator, $folder, $backup);
        if ($lastModified === null) {
            throw new PostmacloneException(
                'Database backups do not appear to be running — no backup files found'
            );
        }

        $now = $this->now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $age = $now->getTimestamp() - $lastModified->getTimestamp();
        if ($age <= self::MAX_AGE_SECONDS) {
            return;
        }

        $days = max(1, intdiv($age, self::MAX_AGE_SECONDS));

        throw new PostmacloneException(
            "Database backups do not appear to be running — last backup file modified {$days} days ago"
        );
    }

    private function isRemoteObjectStore(BackupConfig $backup): bool
    {
        if ($backup->source === BackupConfig::SOURCE_S3) {
            return true;
        }

        $path = $backup->path ?? '';

        return str_starts_with($path, 's3://') || str_starts_with($path, 'spaces://');
    }

    public function listingPrefix(string $key): string
    {
        $star = strpos($key, '*');
        if ($star !== false) {
            $before = substr($key, 0, $star);

            return str_ends_with($before, '/') ? $before : $before . '/';
        }

        if (str_ends_with($key, '/')) {
            return $key;
        }

        $datedFolder = dirname($key);
        $parent = dirname($datedFolder);
        if ($parent === '.' || $parent === '/') {
            return rtrim($datedFolder, '/') . '/';
        }

        return rtrim($parent, '/') . '/';
    }

    private function newestDatedFolder(S3ObjectLocator $locator, string $prefix, BackupConfig $backup): string
    {
        $xml = $this->listObjectsXml($locator, $backup, [
            'list-type' => '2',
            'prefix' => $prefix,
            'delimiter' => '/',
        ]);

        $prefixes = [];
        if (isset($xml->CommonPrefixes)) {
            foreach ($xml->CommonPrefixes as $common) {
                $p = (string) $common->Prefix;
                if ($p !== '') {
                    $prefixes[] = $p;
                }
            }
        }

        if ($prefixes === []) {
            throw new PostmacloneException(
                'Database backups do not appear to be running — no backup files found'
            );
        }

        rsort($prefixes, SORT_STRING);

        return $prefixes[0];
    }

    private function newestObjectTime(
        S3ObjectLocator $locator,
        string $folder,
        BackupConfig $backup,
    ): ?\DateTimeImmutable {
        $newest = null;
        $token = null;

        do {
            $query = [
                'list-type' => '2',
                'prefix' => $folder,
            ];
            if ($token !== null) {
                $query['continuation-token'] = $token;
            }

            $xml = $this->listObjectsXml($locator, $backup, $query);
            if (isset($xml->Contents)) {
                foreach ($xml->Contents as $content) {
                    $raw = (string) $content->LastModified;
                    if ($raw === '') {
                        continue;
                    }
                    $modified = new \DateTimeImmutable($raw);
                    if ($newest === null || $modified > $newest) {
                        $newest = $modified;
                    }
                }
            }

            $truncated = strtolower((string) ($xml->IsTruncated ?? '')) === 'true';
            $next = (string) ($xml->NextContinuationToken ?? '');
            $token = $next !== '' ? $next : null;
        } while ($truncated && $token !== null);

        return $newest;
    }

    /**
     * @param array<string, string> $query
     */
    private function listObjectsXml(
        S3ObjectLocator $locator,
        BackupConfig $backup,
        array $query,
    ): \SimpleXMLElement {
        $credentials = new S3Credentials($backup->credentials);
        [$accessKey, $secretKey, $token] = $credentials->require();
        $signer = $this->signer ?? new S3SigV4Signer();
        $url = $this->bucketUrl($locator, $query);
        $headers = $signer->sign('GET', $url, (string) $locator->region, $accessKey, $secretKey, $token);
        $client = $this->client ?? new Client(['timeout' => 60, 'http_errors' => true]);

        try {
            $response = $client->request('GET', $url, ['headers' => $headers]);
        } catch (GuzzleException $e) {
            throw new PostmacloneException('S3 list failed: ' . $e->getMessage(), 0, $e);
        }

        $body = (string) $response->getBody();
        $xml = simplexml_load_string($body);
        if ($xml === false) {
            throw new PostmacloneException('S3 list returned invalid XML');
        }

        return $xml;
    }

    /**
     * @param array<string, string> $query
     */
    private function bucketUrl(S3ObjectLocator $locator, array $query): string
    {
        ksort($query);
        $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $bucket = $locator->bucket;

        if ($locator->endpoint) {
            $base = rtrim($locator->endpoint, '/');
            if ($locator->pathStyle) {
                return "{$base}/{$bucket}?{$qs}";
            }
            $host = parse_url($base, PHP_URL_HOST);
            $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';

            return "{$scheme}://{$bucket}.{$host}/?{$qs}";
        }

        return "https://{$bucket}.s3.{$locator->region}.amazonaws.com/?{$qs}";
    }
}
