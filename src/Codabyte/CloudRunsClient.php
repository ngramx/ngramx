<?php

declare(strict_types=1);

namespace Ngramx\Codabyte;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

/**
 * Asks the Codabyte server what it is running for a repository, for
 * `ngramx status` to show alongside the local environments.
 *
 * ## Never at the expense of the local answer
 *
 * `ngramx status` is something people type constantly, and its job is to report
 * on the machine in front of them. A remote lookup is a bonus, so this class is
 * built so the worst case is a dim line of text:
 *
 * - a short timeout, because a hung server must not hold up local output;
 * - every failure returns a result rather than throwing;
 * - no retries — this is not `up` waiting for a container to boot, it is a
 *   read that either answers promptly or is not worth waiting for.
 *
 * ## Configuration
 *
 * Reuses `NGRAMX_CODABYTE_HOST` from `ngramx codabyte login`, so a machine set
 * up for one is set up for the other. The API key is what switches this on:
 * without `NGRAMX_CODABYTE_API_KEY` the section is not shown at all.
 */
final class CloudRunsClient
{
    public const ENV_API_KEY = 'NGRAMX_CODABYTE_API_KEY';
    public const ENV_API_URL = 'NGRAMX_CODABYTE_API_URL';

    /**
     * Short on purpose: see the class docblock. Two seconds is long enough for
     * a healthy server across the Atlantic and short enough not to be noticed
     * as a delay before the local overview appears.
     */
    private const TIMEOUT_SECONDS = 2.0;

    /**
     * @param ?\Closure(string $url, array<string, mixed> $options): ResponseInterface $httpRequester
     *        Injection point for tests; production wraps Guzzle.
     * @param array<string, string> $env
     */
    public function __construct(
        private readonly ?\Closure $httpRequester = null,
        private readonly array $env = [],
    ) {
    }

    public static function fromEnvironment(): self
    {
        $env = [];
        foreach ([self::ENV_API_KEY, self::ENV_API_URL, ServerTargetResolver::ENV_HOST] as $key) {
            $value = getenv($key);
            if (is_string($value) && $value !== '') {
                $env[$key] = $value;
            }
        }

        return new self(null, $env);
    }

    public function isConfigured(): bool
    {
        return ($this->env[self::ENV_API_KEY] ?? '') !== '';
    }

    /**
     * Fetch the environments Codabyte holds for $remoteUrl.
     *
     * $remoteUrl is passed through as-is; the server reduces a clone URL, an
     * `org/repo` pair or a bare name to the same repository, so there is no
     * need to agree on a canonical form on this side.
     */
    public function fetch(string $remoteUrl): CloudRunsResult
    {
        if (!$this->isConfigured()) {
            return CloudRunsResult::notConfigured();
        }

        $url = rtrim($this->baseUrl(), '/') . '/v1/runs?repo=' . urlencode($remoteUrl);

        try {
            $response = $this->request($url);
        } catch (GuzzleException | \RuntimeException $e) {
            return CloudRunsResult::unavailable($this->summarise($e->getMessage()));
        }

        $status = $response->getStatusCode();

        // A repository the server has never cloned is not an error worth
        // shouting about — it just has nothing running there.
        if ($status === 404) {
            return CloudRunsResult::of([]);
        }

        if ($status === 401 || $status === 403) {
            return CloudRunsResult::unavailable('rejected the API key (' . $status . ')');
        }

        if ($status >= 400) {
            return CloudRunsResult::unavailable('HTTP ' . $status);
        }

        return $this->parse((string) $response->getBody());
    }

    private function parse(string $body): CloudRunsResult
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return CloudRunsResult::unavailable('unreadable response');
        }

        if (!is_array($decoded) || !is_array($decoded['repositories'] ?? null)) {
            return CloudRunsResult::unavailable('unexpected response shape');
        }

        $runs = [];
        foreach ($decoded['repositories'] as $repository) {
            if (!is_array($repository)) {
                continue;
            }

            // A repository the server could not inspect reports its own error.
            // Surface that rather than silently showing it as empty.
            if (is_string($repository['error'] ?? null) && $repository['error'] !== '') {
                return CloudRunsResult::unavailable($this->summarise($repository['error']));
            }

            foreach (is_array($repository['worktrees'] ?? null) ? $repository['worktrees'] : [] as $worktree) {
                if (is_array($worktree)) {
                    $runs[] = CloudRun::fromArray($worktree);
                }
            }
        }

        return CloudRunsResult::of($runs);
    }

    private function request(string $url): ResponseInterface
    {
        $options = [
            'headers' => [
                'x-api-key' => $this->env[self::ENV_API_KEY] ?? '',
                'Accept' => 'application/json',
            ],
            'connect_timeout' => self::TIMEOUT_SECONDS,
            'timeout' => self::TIMEOUT_SECONDS,
            // We handle the status code ourselves: a 404 is a legitimate answer
            // here, not an exception.
            'http_errors' => false,
        ];

        if ($this->httpRequester !== null) {
            return ($this->httpRequester)($url, $options);
        }

        return (new Client())->request('GET', $url, $options);
    }

    /**
     * The API base URL: an explicit override, or https:// the host that
     * `ngramx codabyte login` would SSH to.
     */
    private function baseUrl(): string
    {
        $explicit = $this->env[self::ENV_API_URL] ?? '';
        if ($explicit !== '') {
            return $explicit;
        }

        $host = $this->env[ServerTargetResolver::ENV_HOST] ?? ServerTarget::DEFAULT_HOST;

        return 'https://' . $host;
    }

    /**
     * Squeeze a message into something that fits on one status line.
     *
     * Guzzle's connection errors run to several lines with the full request
     * context, which would swamp the overview it is appended to.
     */
    private function summarise(string $message): string
    {
        $firstLine = trim(explode("\n", $message)[0]);

        return mb_strimwidth($firstLine, 0, 120, '…');
    }
}
