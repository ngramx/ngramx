<?php

declare(strict_types=1);

namespace Ngramx\Tls;

/**
 * Inspect the TLS certificate that the project's reverse proxy is using.
 *
 * `ngramx up` doesn't generate certificates — `.ngramx/pre-start.sh`
 * typically lays down a self-signed cert via OpenSSL, and `ngramx secure`
 * (separately) installs a browser-trusted one via mkcert. This inspector
 * lets `ngramx up` tell, at the end of a successful start, whether what
 * the user is about to load in their browser is going to be trusted.
 *
 * The check is read-only: we never touch the cert, just parse it.
 */
class CertInspector
{
    /**
     * Locate the cert for $appUrl under $configDir/$sslPath/{hostname}.crt
     * and return a {@see CertInfo} describing its provenance.
     *
     * Returns null when there's no host in the URL (e.g. `http://`) or no
     * cert file at the expected path. Both are normal for non-TLS setups
     * and we deliberately stay quiet about them.
     */
    public function inspectForAppUrl(string $appUrl, string $configDir, string $sslPath): ?CertInfo
    {
        $hostname = $this->extractHostname($appUrl);
        if ($hostname === null) {
            return null;
        }

        $certPath = rtrim($configDir, '/') . '/' . trim($sslPath, '/') . '/' . $hostname . '.crt';
        if (!is_file($certPath)) {
            return null;
        }

        return $this->inspectPath($certPath);
    }

    /**
     * Parse a cert at the given absolute path and return its provenance.
     * Returns null only when the file cannot be parsed as an X.509 PEM
     * (corrupt cert, unreadable file, etc.) — callers should treat that
     * the same as "no cert present" because we can't reason about it.
     */
    public function inspectPath(string $certPath): ?CertInfo
    {
        $pem = @file_get_contents($certPath);
        if ($pem === false || $pem === '') {
            return null;
        }

        return $this->inspectPem($pem, $certPath);
    }

    /**
     * Parse cert PEM content directly. Exposed for tests so we don't have to
     * scatter throwaway certificate files across tmp.
     */
    public function inspectPem(string $pem, ?string $sourcePath = null): ?CertInfo
    {
        $parsed = @openssl_x509_parse($pem);
        if (!is_array($parsed)) {
            return null;
        }

        $subject = $this->joinDnComponents($parsed['subject'] ?? []);
        $issuer = $this->joinDnComponents($parsed['issuer'] ?? []);

        $subjectCn = $this->dnComponent($parsed['subject'] ?? [], 'CN');
        $issuerCn = $this->dnComponent($parsed['issuer'] ?? [], 'CN');
        $issuerOrg = $this->dnComponent($parsed['issuer'] ?? [], 'O');

        $isSelfSigned = $subject !== '' && $subject === $issuer;
        $isMkcert = $this->looksLikeMkcert($issuerCn, $issuerOrg);

        return new CertInfo(
            path: $sourcePath,
            subjectCn: $subjectCn,
            issuerCn: $issuerCn,
            issuerOrg: $issuerOrg,
            isSelfSigned: $isSelfSigned,
            isMkcert: $isMkcert,
            subjectAltNames: $this->parseSubjectAltNames($parsed),
        );
    }

    /**
     * Extract the DNS names from the parsed cert's subjectAltName extension
     * (openssl formats it as "DNS:a.localhost, DNS:b.localhost, IP Address:…").
     *
     * @param array<string, mixed> $parsed
     * @return list<string>
     */
    private function parseSubjectAltNames(array $parsed): array
    {
        $extensions = $parsed['extensions'] ?? null;
        $san = is_array($extensions) ? ($extensions['subjectAltName'] ?? null) : null;
        if (!is_string($san) || $san === '') {
            return [];
        }

        $names = [];
        foreach (explode(',', $san) as $entry) {
            $entry = trim($entry);
            if (str_starts_with($entry, 'DNS:')) {
                $names[] = substr($entry, 4);
            }
        }

        return $names;
    }

    private function extractHostname(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }

        return $host;
    }

    /**
     * @param array<string, mixed> $dn
     */
    private function dnComponent(array $dn, string $key): ?string
    {
        $value = $dn[$key] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }
        // openssl_x509_parse occasionally returns lists for multi-value DN parts.
        if (is_array($value) && isset($value[0]) && is_string($value[0])) {
            return $value[0];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $dn
     */
    private function joinDnComponents(array $dn): string
    {
        $parts = [];
        foreach ($dn as $k => $v) {
            if (is_array($v)) {
                $v = implode('+', array_filter($v, 'is_string'));
            }
            if (is_string($v)) {
                $parts[] = $k . '=' . $v;
            }
        }

        sort($parts);
        return implode(',', $parts);
    }

    private function looksLikeMkcert(?string $issuerCn, ?string $issuerOrg): bool
    {
        $haystacks = array_filter([$issuerCn, $issuerOrg], 'is_string');
        foreach ($haystacks as $h) {
            if (stripos($h, 'mkcert') !== false) {
                return true;
            }
        }

        return false;
    }
}
