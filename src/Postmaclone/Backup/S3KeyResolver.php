<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Backup;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Ngramx\Postmaclone\Exception\PostmacloneException;

/**
 * Resolves Spaces/S3 keys that include a changing daily folder into a concrete object.
 *
 * Shared Forge layout:
 *   database-backups/all/YYYYMMDDHHMMSS/earl_kendrick_prod.sql.gz
 *
 * Stable config options (pick one):
 *   path with a single-star folder segment, then the dump basename
 *   path ending in "/" plus backup.file basename
 *
 * The star segment (or trailing "/" + file) selects the newest dated folder; the
 * dump basename is always project-specific and must be configured explicitly.
 */
class S3KeyResolver
{
    public function __construct(
        private readonly S3ObjectLocator $locator,
        private readonly ?Client $client = null,
        private readonly ?S3SigV4Signer $signer = null,
        private readonly ?string $file = null,
        private readonly ?S3Credentials $credentials = null,
    ) {
    }

    /**
     * Returns a locator pointing at a concrete object key (never a bare prefix).
     */
    public function resolve(): S3ObjectLocator
    {
        $key = $this->locator->key;
        $file = $this->normalizeFile($this->file);

        if (str_contains($key, '*')) {
            $objectKey = $this->resolveGlobKey($key, $file);
        } elseif (str_ends_with($key, '/')) {
            if ($file === null) {
                throw new PostmacloneException(
                    'S3 backup path ends with "/" but no dump filename was given. '
                    . 'Shared daily folders contain many projects — set backup.file '
                    . '(e.g. earl_kendrick_prod.sql.gz) or use a path like '
                    . '"…/database-backups/all/*/earl_kendrick_prod.sql.gz".'
                );
            }
            $folder = $this->latestCommonPrefix($key);
            $objectKey = rtrim($folder, '/') . '/' . $file;
        } else {
            return $this->locator;
        }

        $this->assertObjectExists($objectKey);

        return new S3ObjectLocator(
            bucket: $this->locator->bucket,
            key: $objectKey,
            region: $this->locator->region,
            endpoint: $this->locator->endpoint,
            pathStyle: $this->locator->pathStyle,
        );
    }

    public function needsResolution(string $key): bool
    {
        return str_contains($key, '*') || str_ends_with($key, '/');
    }

    private function resolveGlobKey(string $key, ?string $file): string
    {
        // Support a single "*" path segment for the dated folder.
        // e.g. database-backups/all/*/earl_kendrick_prod.sql.gz
        if (substr_count($key, '*') !== 1) {
            throw new PostmacloneException(
                'S3 backup path may contain only one "*" (for the daily folder segment)'
            );
        }

        $starPos = strpos($key, '*');
        if ($starPos === false) {
            throw new PostmacloneException('Invalid S3 glob path');
        }

        $before = substr($key, 0, $starPos);
        $after = substr($key, $starPos + 1);

        // "*foo" or "bar*" without being a full path segment is not supported
        if (($before !== '' && !str_ends_with($before, '/'))
            || ($after !== '' && !str_starts_with($after, '/') && $after !== '')) {
            // allow trailing "/*" with file from backup.file
            if (str_ends_with($key, '/*') || str_ends_with($key, '*')) {
                if ($file === null) {
                    throw new PostmacloneException(
                        'S3 path ends with "*" but backup.file is not set. '
                        . 'Example: path "…/all/*" with file "earl_kendrick_prod.sql.gz"'
                    );
                }
                $prefix = rtrim($before, '/') . '/';
                $folder = $this->latestCommonPrefix($prefix);

                return rtrim($folder, '/') . '/' . $file;
            }

            throw new PostmacloneException(
                'S3 "*" must be a full path segment, e.g. database-backups/all/*/earl_kendrick_prod.sql.gz'
            );
        }

        $prefix = $before; // already ends with /
        $suffix = ltrim($after, '/'); // earl_kendrick_prod.sql.gz or empty
        if ($suffix === '') {
            if ($file === null) {
                throw new PostmacloneException(
                    'S3 path has a "*" folder segment but no dump filename. '
                    . 'Use …/*/earl_kendrick_prod.sql.gz or set backup.file'
                );
            }
            $suffix = $file;
        } elseif ($file !== null && $file !== $suffix) {
            throw new PostmacloneException(
                "S3 path filename '{$suffix}' conflicts with backup.file '{$file}'"
            );
        }

        $folder = $this->latestCommonPrefix($prefix);

        return rtrim($folder, '/') . '/' . $suffix;
    }

    private function normalizeFile(?string $file): ?string
    {
        if ($file === null) {
            return null;
        }
        $file = trim($file);
        if ($file === '') {
            return null;
        }
        if (str_contains($file, '/') || str_contains($file, '\\')) {
            throw new PostmacloneException('backup.file must be a basename only (e.g. earl_kendrick_prod.sql.gz)');
        }

        return $file;
    }

    private function latestCommonPrefix(string $prefix): string
    {
        if (!str_ends_with($prefix, '/')) {
            $prefix .= '/';
        }

        $prefixes = $this->listCommonPrefixes($prefix);
        if ($prefixes === []) {
            throw new PostmacloneException(
                "No daily backup folders found under s3://{$this->locator->bucket}/{$prefix}"
            );
        }

        rsort($prefixes, SORT_STRING);

        return $prefixes[0];
    }

    /**
     * @return list<string>
     */
    private function listCommonPrefixes(string $prefix): array
    {
        $xml = $this->listObjectsXml([
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

        return $prefixes;
    }

    private function assertObjectExists(string $objectKey): void
    {
        $xml = $this->listObjectsXml([
            'list-type' => '2',
            'prefix' => $objectKey,
            'max-keys' => '1',
        ]);

        $found = false;
        if (isset($xml->Contents)) {
            foreach ($xml->Contents as $content) {
                if ((string) $content->Key === $objectKey) {
                    $found = true;
                    break;
                }
            }
        }

        if (!$found) {
            throw new PostmacloneException(
                "Backup object not found: s3://{$this->locator->bucket}/{$objectKey}"
            );
        }
    }

    /**
     * @param array<string, string> $query
     */
    private function listObjectsXml(array $query): \SimpleXMLElement
    {
        [$accessKey, $secretKey, $token] = $this->credentials();
        $signer = $this->signer ?? new S3SigV4Signer();
        $url = $this->bucketUrl($query);
        $headers = $signer->sign('GET', $url, (string) $this->locator->region, $accessKey, $secretKey, $token);
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
    private function bucketUrl(array $query): string
    {
        ksort($query);
        $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $bucket = $this->locator->bucket;

        if ($this->locator->endpoint) {
            $base = rtrim($this->locator->endpoint, '/');
            if ($this->locator->pathStyle) {
                return "{$base}/{$bucket}?{$qs}";
            }
            $host = parse_url($base, PHP_URL_HOST);
            $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';

            return "{$scheme}://{$bucket}.{$host}/?{$qs}";
        }

        return "https://{$bucket}.s3.{$this->locator->region}.amazonaws.com/?{$qs}";
    }

    /**
     * @return array{0: string, 1: string, 2: string|null}
     */
    /**
     * @return array{0: string, 1: string, 2: string|null}
     */
    private function credentials(): array
    {
        return ($this->credentials ?? new S3Credentials())->require();
    }
}
