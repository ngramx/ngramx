<?php

declare(strict_types=1);

namespace Ngramx\Tls;

/**
 * Provenance summary for a single TLS certificate, produced by
 * {@see CertInspector}.
 */
readonly class CertInfo
{
    public function __construct(
        public ?string $path,
        public ?string $subjectCn,
        public ?string $issuerCn,
        public ?string $issuerOrg,
        public bool $isSelfSigned,
        public bool $isMkcert,
    ) {
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
