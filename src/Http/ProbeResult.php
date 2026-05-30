<?php

declare(strict_types=1);

namespace Ngramx\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Immutable result of an {@see AppUrlProbe::probe()} call.
 *
 * A probe is considered "healthy" when the upstream produced *any* response
 * with an HTTP status code below 500 — including redirects (302 to /login is
 * fine), authentication challenges (401, 403) and not-found pages (404). The
 * thing we explicitly flag as unhealthy is a 5xx, because the whole point of
 * this check is catching reverse-proxy 502/503/504 errors where the upstream
 * is broken even though Docker says "running".
 */
readonly class ProbeResult
{
    private function __construct(
        public string $url,
        public bool $reachable,
        public ?int $statusCode,
        public ?string $error,
        public bool $connectionRefused,
    ) {
    }

    public static function fromResponse(string $url, ResponseInterface $response): self
    {
        return new self(
            url: $url,
            reachable: true,
            statusCode: $response->getStatusCode(),
            error: null,
            connectionRefused: false,
        );
    }

    public static function failure(string $url, string $error, bool $connectionRefused = false): self
    {
        return new self(
            url: $url,
            reachable: false,
            statusCode: null,
            error: $error,
            connectionRefused: $connectionRefused,
        );
    }

    /**
     * Did the upstream produce a non-5xx response? See class doc for rationale
     * on why 3xx/4xx count as healthy here.
     */
    public function isHealthy(): bool
    {
        if (!$this->reachable || $this->statusCode === null) {
            return false;
        }

        return $this->statusCode < 500;
    }

    /**
     * A short, human-friendly description of what went wrong, designed to be
     * surfaced as a single line in `ngramx up` failure output.
     */
    public function describeFailure(): string
    {
        if ($this->isHealthy()) {
            return 'OK';
        }

        if ($this->connectionRefused) {
            return sprintf('Could not reach %s — connection refused or timed out.', $this->url);
        }

        if (!$this->reachable) {
            return sprintf('Could not reach %s — %s', $this->url, $this->error ?? 'unknown error');
        }

        return sprintf('%s responded with HTTP %d', $this->url, (int) $this->statusCode);
    }
}
