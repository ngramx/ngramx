<?php

declare(strict_types=1);

namespace Ngramx\Tls;

/**
 * Provenance summary for a single TLS certificate, produced by
 * {@see CertInspector}.
 */
readonly class CertInfo
{
    /**
     * @param list<string> $subjectAltNames DNS names from the SAN extension
     */
    public function __construct(
        public ?string $path,
        public ?string $subjectCn,
        public ?string $issuerCn,
        public ?string $issuerOrg,
        public bool $isSelfSigned,
        public bool $isMkcert,
        public array $subjectAltNames = [],
    ) {
    }

    /**
     * Whether this certificate is valid for the given hostname, honouring
     * single-label wildcards (`*.localhost` covers `foo.localhost` but not
     * `a.b.localhost`). Falls back to the subject CN when the cert carries no
     * SAN extension (legacy self-signed certs) — browsers no longer accept
     * CN-only matches, but for our purposes it still identifies which host the
     * cert was minted for.
     */
    public function coversHost(string $host): bool
    {
        $host = strtolower($host);
        $names = $this->subjectAltNames !== []
            ? $this->subjectAltNames
            : ($this->subjectCn !== null ? [$this->subjectCn] : []);

        foreach ($names as $name) {
            $name = strtolower($name);
            if ($name === $host) {
                return true;
            }
            if (
                str_starts_with($name, '*.')
                && str_ends_with($host, substr($name, 1))
                && substr_count($host, '.') === substr_count($name, '.')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when this certificate is browser-trustable: signed by mkcert's
     * local CA. mkcert installs that CA into the system + Firefox trust
     * stores, so a mkcert-issued cert is the only kind that `ngramx up`
     * can safely declare "ready to open in a browser".
     */
    public function isBrowserTrusted(): bool
    {
        return $this->isMkcert;
    }

    public function describeIssuer(): string
    {
        $parts = array_values(array_filter([
            $this->issuerCn,
            $this->issuerOrg !== null && $this->issuerOrg !== $this->issuerCn ? $this->issuerOrg : null,
        ], static fn ($v) => is_string($v) && $v !== ''));

        return $parts === [] ? '(unknown issuer)' : implode(' / ', $parts);
    }
}
