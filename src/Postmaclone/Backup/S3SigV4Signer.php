<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Backup;

/**
 * Minimal AWS Signature Version 4 signer for S3-compatible GET/HEAD.
 */
class S3SigV4Signer
{
    /**
     * @return array<string, string>
     */
    public function sign(
        string $method,
        string $url,
        string $region,
        string $accessKey,
        string $secretKey,
        ?string $sessionToken = null,
        string $service = 's3',
    ): array {
        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['host'])) {
            throw new \InvalidArgumentException('Invalid URL for signing');
        }

        $host = $parsed['host'];
        $path = $parsed['path'] ?? '/';
        $query = $this->canonicalQuery($parsed['query'] ?? '');
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $payloadHash = hash('sha256', '');

        $headers = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
        ];
        if ($sessionToken !== null && $sessionToken !== '') {
            $headers['x-amz-security-token'] = $sessionToken;
        }

        ksort($headers);
        $canonicalHeaders = '';
        $signedHeadersList = [];
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name . ':' . trim($value) . "\n";
            $signedHeadersList[] = $name;
        }
        $signedHeaders = implode(';', $signedHeadersList);

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            $this->canonicalUri($path === '' ? '/' : $path),
            $query,
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $credentialScope = "{$dateStamp}/{$region}/{$service}/aws4_request";
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = $this->signingKey($secretKey, $dateStamp, $region, $service);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $authorization = 'AWS4-HMAC-SHA256 Credential='
            . $accessKey . '/' . $credentialScope
            . ', SignedHeaders=' . $signedHeaders
            . ', Signature=' . $signature;

        $out = [
            'Authorization' => $authorization,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
            'Host' => $host,
        ];
        if ($sessionToken !== null && $sessionToken !== '') {
            $out['x-amz-security-token'] = $sessionToken;
        }

        return $out;
    }

    private function canonicalUri(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }

        $segments = explode('/', $path);
        $encoded = array_map(static fn (string $s) => rawurlencode(rawurldecode($s)), $segments);

        return implode('/', $encoded);
    }

    private function canonicalQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $pairs = [];
        foreach (explode('&', $query) as $part) {
            if ($part === '') {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
            $pairs[rawurldecode($k)] = rawurldecode($v);
        }
        ksort($pairs);

        $out = [];
        foreach ($pairs as $k => $v) {
            $out[] = rawurlencode($k) . '=' . rawurlencode($v);
        }

        return implode('&', $out);
    }

    private function signingKey(string $secret, string $date, string $region, string $service): string
    {
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);

        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }
}
