<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Codabyte;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Ngramx\Codabyte\CloudRunsClient;
use Ngramx\Codabyte\ServerTarget;
use Ngramx\Codabyte\ServerTargetResolver;
use PHPUnit\Framework\TestCase;

class CloudRunsClientTest extends TestCase
{
    private const KEY = 'test-api-key';

    public function test_it_is_not_configured_without_an_api_key(): void
    {
        $client = new CloudRunsClient(null, []);

        $this->assertFalse($client->isConfigured());
    }

    /**
     * No key means no request at all: a machine that has never been set up for
     * Codabyte should not be making network calls on every `ngramx status`.
     */
    public function test_it_makes_no_request_when_not_configured(): void
    {
        $called = false;
        $client = new CloudRunsClient(function () use (&$called): Response {
            $called = true;
            return new Response(200, [], '{}');
        }, []);

        $result = $client->fetch('git@github.com:org/repo.git');

        $this->assertFalse($called);
        $this->assertFalse($result->configured);
        $this->assertSame([], $result->runs);
    }

    public function test_it_parses_runs_from_a_successful_response(): void
    {
        $client = $this->clientReturning(new Response(200, [], (string) json_encode([
            'schema' => 1,
            'repositories' => [[
                'name' => 'cortex',
                'error' => null,
                'worktrees' => [
                    [
                        'name' => 'cor-301-cortex',
                        'branch' => 'cor-301',
                        'running' => true,
                        'url' => 'https://cor-301.localhost:8443',
                        'agentState' => 'running',
                        'agent' => ['issue' => 'COR-301', 'startedAt' => '2026-01-01T00:00:00Z'],
                    ],
                    [
                        'name' => 'cor-302-cortex',
                        'branch' => 'cor-302',
                        'running' => false,
                        'url' => null,
                        'agentState' => 'succeeded',
                        'agent' => ['issue' => 'COR-302'],
                    ],
                ],
            ]],
        ])));

        $result = $client->fetch('git@github.com:org/cortex.git');

        $this->assertTrue($result->configured);
        $this->assertFalse($result->failed());
        $this->assertCount(2, $result->runs);

        $this->assertSame('cor-301-cortex', $result->runs[0]->name);
        $this->assertSame('cor-301', $result->runs[0]->branch);
        $this->assertTrue($result->runs[0]->running);
        $this->assertSame('running', $result->runs[0]->agentState);
        $this->assertSame('COR-301', $result->runs[0]->issue);
        $this->assertSame('2026-01-01T00:00:00Z', $result->runs[0]->startedAt);

        $this->assertSame('succeeded', $result->runs[1]->agentState);
        $this->assertNull($result->runs[1]->url);
    }

    public function test_it_flattens_runs_across_repositories(): void
    {
        $client = $this->clientReturning(new Response(200, [], (string) json_encode([
            'repositories' => [
                ['name' => 'a', 'error' => null, 'worktrees' => [['name' => 'one', 'agentState' => 'none']]],
                ['name' => 'b', 'error' => null, 'worktrees' => [['name' => 'two', 'agentState' => 'none']]],
            ],
        ])));

        $this->assertCount(2, $client->fetch('repo')->runs);
    }

    /**
     * A repository the server has never cloned has nothing running on it — which
     * is an answer, not a failure worth putting on screen.
     */
    public function test_a_404_reports_no_runs_rather_than_an_error(): void
    {
        $result = $this->clientReturning(new Response(404, [], '{"error":"no checkout"}'))
            ->fetch('git@github.com:org/unknown.git');

        $this->assertTrue($result->configured);
        $this->assertFalse($result->failed());
        $this->assertSame([], $result->runs);
    }

    public function test_a_rejected_key_is_reported_as_such(): void
    {
        foreach ([401, 403] as $status) {
            $result = $this->clientReturning(new Response($status))->fetch('repo');

            $this->assertTrue($result->failed());
            $this->assertStringContainsString('API key', (string) $result->error);
            $this->assertStringContainsString((string) $status, (string) $result->error);
        }
    }

    public function test_a_server_error_is_reported_with_its_status(): void
    {
        $result = $this->clientReturning(new Response(500))->fetch('repo');

        $this->assertTrue($result->failed());
        $this->assertSame('HTTP 500', $result->error);
    }

    public function test_unreadable_json_is_reported_rather_than_thrown(): void
    {
        $result = $this->clientReturning(new Response(200, [], 'not json at all'))->fetch('repo');

        $this->assertTrue($result->failed());
        $this->assertSame('unreadable response', $result->error);
    }

    public function test_an_unexpected_shape_is_reported(): void
    {
        $result = $this->clientReturning(new Response(200, [], '{"something":"else"}'))->fetch('repo');

        $this->assertTrue($result->failed());
        $this->assertSame('unexpected response shape', $result->error);
    }

    /**
     * The server reports a repository it could not inspect — an Ngramx build
     * too old for `status --json`, say. Showing that as "no environments" would
     * be misleading.
     */
    public function test_a_repository_level_error_is_surfaced(): void
    {
        $result = $this->clientReturning(new Response(200, [], (string) json_encode([
            'repositories' => [[
                'name' => 'cortex',
                'error' => 'The "--json" option does not exist.',
                'worktrees' => [],
            ]],
        ])))->fetch('repo');

        $this->assertTrue($result->failed());
        $this->assertStringContainsString('--json', (string) $result->error);
    }

    /**
     * A server that is down must cost one line of output, not a stack trace.
     */
    public function test_a_connection_failure_is_reported_rather_than_thrown(): void
    {
        $client = new CloudRunsClient(
            function (): Response {
                throw new ConnectException(
                    "cURL error 7: Failed to connect to codabyte.gigabyte.software port 443\nwith a second line of context",
                    new Request('GET', 'https://codabyte.gigabyte.software/v1/runs')
                );
            },
            [CloudRunsClient::ENV_API_KEY => self::KEY]
        );

        $result = $client->fetch('repo');

        $this->assertTrue($result->failed());
        $this->assertStringContainsString('Failed to connect', (string) $result->error);
        // Trimmed to a single line so it cannot swamp the overview it follows.
        $this->assertStringNotContainsString("\n", (string) $result->error);
    }

    public function test_a_long_error_is_truncated_to_one_line(): void
    {
        $client = new CloudRunsClient(
            fn (): Response => throw new ConnectException(
                str_repeat('x', 500),
                new Request('GET', 'https://example.test')
            ),
            [CloudRunsClient::ENV_API_KEY => self::KEY]
        );

        $this->assertLessThanOrEqual(121, mb_strwidth((string) $client->fetch('repo')->error));
    }

    public function test_it_sends_the_api_key_and_encodes_the_repository(): void
    {
        $seen = ['url' => '', 'options' => []];

        $client = new CloudRunsClient(
            function (string $url, array $options) use (&$seen): Response {
                $seen = ['url' => $url, 'options' => $options];
                return new Response(200, [], '{"repositories":[]}');
            },
            [CloudRunsClient::ENV_API_KEY => self::KEY]
        );

        $client->fetch('git@github.com:org/repo.git');

        $this->assertStringContainsString('/v1/runs?repo=', $seen['url']);
        $this->assertStringContainsString('git%40github.com%3Aorg%2Frepo.git', $seen['url']);
        $this->assertSame(self::KEY, $seen['options']['headers']['x-api-key']);
        // Status codes are interpreted here, not thrown by the transport.
        $this->assertFalse($seen['options']['http_errors']);
    }

    public function test_it_defaults_to_the_host_used_by_codabyte_login(): void
    {
        $seen = '';

        $client = new CloudRunsClient(
            function (string $url) use (&$seen): Response {
                $seen = $url;
                return new Response(200, [], '{"repositories":[]}');
            },
            [CloudRunsClient::ENV_API_KEY => self::KEY]
        );

        $client->fetch('repo');

        $this->assertStringStartsWith('https://' . ServerTarget::DEFAULT_HOST . '/v1/runs', $seen);
    }

    public function test_the_host_env_var_moves_the_api_with_it(): void
    {
        $seen = '';

        $client = new CloudRunsClient(
            function (string $url) use (&$seen): Response {
                $seen = $url;
                return new Response(200, [], '{"repositories":[]}');
            },
            [
                CloudRunsClient::ENV_API_KEY => self::KEY,
                ServerTargetResolver::ENV_HOST => 'staging.example.test',
            ]
        );

        $client->fetch('repo');

        $this->assertStringStartsWith('https://staging.example.test/v1/runs', $seen);
    }

    public function test_an_explicit_api_url_wins(): void
    {
        $seen = '';

        $client = new CloudRunsClient(
            function (string $url) use (&$seen): Response {
                $seen = $url;
                return new Response(200, [], '{"repositories":[]}');
            },
            [
                CloudRunsClient::ENV_API_KEY => self::KEY,
                CloudRunsClient::ENV_API_URL => 'http://localhost:8080/',
                ServerTargetResolver::ENV_HOST => 'ignored.example.test',
            ]
        );

        $client->fetch('repo');

        // Trailing slash on the configured base must not double up.
        $this->assertStringStartsWith('http://localhost:8080/v1/runs', $seen);
    }

    private function clientReturning(Response $response): CloudRunsClient
    {
        return new CloudRunsClient(
            fn (): Response => $response,
            [CloudRunsClient::ENV_API_KEY => self::KEY]
        );
    }
}
