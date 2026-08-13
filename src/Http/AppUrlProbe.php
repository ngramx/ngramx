<?php

declare(strict_types=1);

namespace Ngramx\Http;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP(S) reachability probe for the running development environment.
 *
 * Used at the tail of `ngramx up` to confirm the URL declared in
 * `docker.app_url` actually responds. Catches the silent-success failure
 * mode where Docker reports every container as "running" but the upstream
 * (e.g. php-fpm behind nginx) is broken and the user is staring at a 502.
 *
 * Self-signed certs are tolerated on purpose — they're normal for local dev
 * (see {@see \Ngramx\Tls\CertInspector}) and we don't want TLS strictness to
 * mask the real "is the app responding?" question we're trying to answer.
 */
class AppUrlProbe
{
    /**
     * @param (\Closure(string $method, string $url, array<string, mixed> $options): ResponseInterface)|null $httpRequester
     *        Optional injection point for tests. Production wraps Guzzle.
     */
    public function __construct(
        private readonly ?\Closure $httpRequester = null,
        private readonly float $connectTimeout = 3.0,
        private readonly float $requestTimeout = 5.0,
    ) {
    }

    /**
     * Perform up to $attempts probes against $url, sleeping $retrySeconds
     * between attempts. Returns the final {@see ProbeResult}.
     *
     * Retries are useful when the app container has only just started its
     * own bootstrap (composer install / cache warm) and a single GET fired
     * the instant compose returned would be premature.
     */
    public function probe(string $url, int $attempts = 1, int $retrySeconds = 2): ProbeResult
    {
        return $this->probeWithHost($url, null, $attempts, $retrySeconds);
    }

    /**
     * Like {@see probe()} but sends an explicit `Host` header while still
     * connecting to the URL's host:port. Used to ask "does the app serve this
     * (invented) hostname?" against a loopback address, which is how the worktree
     * URL resolver distinguishes host-agnostic apps from host-routed ones.
     */
    public function probeWithHost(string $url, ?string $hostHeader, int $attempts = 1, int $retrySeconds = 2): ProbeResult
    {
        $attempts = max(1, $attempts);

        $last = ProbeResult::failure($url, 'No probe attempt was made.');
        for ($i = 0; $i < $attempts; $i++) {
            $last = $this->probeOnce($url, $hostHeader);
            if ($last->isHealthy()) {
                return $last;
            }
            if ($i + 1 < $attempts) {
                sleep(max(0, $retrySeconds));
            }
        }

        return $last;
    }

    private function probeOnce(string $url, ?string $hostHeader = null): ProbeResult
    {
        $headers = ['User-Agent' => 'ngramx/AppUrlProbe'];
        if ($hostHeader !== null) {
            $headers['Host'] = $hostHeader;
        }

        $options = [
            'allow_redirects' => false,
            'connect_timeout' => $this->connectTimeout,
            'timeout' => $this->requestTimeout,
            'verify' => false,
            'http_errors' => false,
            'headers' => $headers,
        ];

        try {
            $response = ($this->httpRequester ?? $this->defaultRequester())(
                'GET',
                $url,
                $options,
            );

            return ProbeResult::fromResponse($url, $response);
        } catch (ConnectException $e) {
            return ProbeResult::failure(
                $url,
                'Could not connect: ' . $e->getMessage(),
                connectionRefused: true,
            );
        } catch (RequestException $e) {
            $response = $e->hasResponse() ? $e->getResponse() : null;
            if ($response instanceof ResponseInterface) {
                return ProbeResult::fromResponse($url, $response);
            }

            return ProbeResult::failure($url, $e->getMessage());
        } catch (GuzzleException $e) {
            return ProbeResult::failure($url, $e->getMessage());
        } catch (\Throwable $e) {
            return ProbeResult::failure($url, 'Unexpected probe error: ' . $e->getMessage());
        }
    }

    /**
     * Production HTTP client. When the URL host is an IP and a `Host` header
     * is set, pin DNS to that IP via CURLOPT_RESOLVE so curl still uses the
     * hostname for TLS SNI (nginx name-based vhosts).
     */
    private function defaultRequester(): \Closure
    {
        return static function (string $method, string $url, array $options): ResponseInterface {
            [$url, $options] = self::applySniForIpTarget($url, $options);

            $client = new \GuzzleHttp\Client();

            return $client->request($method, $url, $options);
        };
    }

    /**
     * @param array<string, mixed> $options
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function applySniForIpTarget(string $url, array $options): array
    {
        $hostHeader = $options['headers']['Host'] ?? null;
        if (!is_string($hostHeader) || $hostHeader === '' || !\defined('CURLOPT_RESOLVE')) {
            return [$url, $options];
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'], $parts['scheme'])) {
            return [$url, $options];
        }

        $ip = (string) $parts['host'];
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return [$url, $options];
        }

        $port = $parts['port'] ?? match (strtolower((string) $parts['scheme'])) {
            'https' => 443,
            'http' => 80,
            default => null,
        };
        if ($port === null) {
            return [$url, $options];
        }

        $curl = is_array($options['curl'] ?? null) ? $options['curl'] : [];
        $curl[\CURLOPT_RESOLVE] = [sprintf('%s:%d:%s', $hostHeader, $port, $ip)];
        $options['curl'] = $curl;

        return [LoopbackUrl::withHost($url, $hostHeader), $options];
    }
}
