<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Http;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Ngramx\Http\AppUrlProbe;
use Ngramx\Http\ProbeResult;
use PHPUnit\Framework\TestCase;

class AppUrlProbeTest extends TestCase
{
    public function test_probe_returns_healthy_for_200(): void
    {
        $probe = new AppUrlProbe($this->requesterReturning(new Response(200)));

        $result = $probe->probe('https://app.localhost');

        $this->assertTrue($result->isHealthy());
        $this->assertSame(200, $result->statusCode);
        $this->assertNull($result->error);
    }

    public function test_probe_treats_302_redirect_as_healthy(): void
    {
        $probe = new AppUrlProbe($this->requesterReturning(new Response(302, ['Location' => '/login'])));

        $result = $probe->probe('https://app.localhost');

        $this->assertTrue($result->isHealthy(), 'A 302 redirect to /login is the normal "logged out" landing state and must count as healthy.');
        $this->assertSame(302, $result->statusCode);
    }

    public function test_probe_treats_404_as_healthy_because_upstream_responded(): void
    {
        $probe = new AppUrlProbe($this->requesterReturning(new Response(404)));

        $result = $probe->probe('https://app.localhost');

        $this->assertTrue($result->isHealthy(), 'A 404 still proves the application is serving requests; the probe is for upstream liveness, not route correctness.');
    }

    public function test_probe_flags_502_as_unhealthy(): void
    {
        $probe = new AppUrlProbe($this->requesterReturning(new Response(502)));

        $result = $probe->probe('https://app.localhost');

        $this->assertFalse($result->isHealthy());
        $this->assertSame(502, $result->statusCode);
        $this->assertStringContainsString('HTTP 502', $result->describeFailure());
    }

    public function test_probe_flags_503_as_unhealthy(): void
    {
        $probe = new AppUrlProbe($this->requesterReturning(new Response(503)));

        $result = $probe->probe('https://app.localhost');

        $this->assertFalse($result->isHealthy());
        $this->assertSame(503, $result->statusCode);
    }

    public function test_probe_returns_connection_refused_diagnostic(): void
    {
        $exception = new ConnectException('cURL error 7: Failed to connect', new Request('GET', 'https://app.localhost'));
        $probe = new AppUrlProbe($this->requesterThrowing($exception));

        $result = $probe->probe('https://app.localhost');

        $this->assertFalse($result->isHealthy());
        $this->assertTrue($result->connectionRefused);
        $this->assertStringContainsString('connection refused or timed out', $result->describeFailure());
    }

    public function test_probe_retries_when_first_attempt_is_unhealthy(): void
    {
        $responses = [new Response(502), new Response(502), new Response(200)];
        $callCount = 0;
        $probe = new AppUrlProbe(function () use (&$callCount, $responses) {
            return $responses[$callCount++] ?? new Response(200);
        });

        $result = $probe->probe('https://app.localhost', attempts: 3, retrySeconds: 0);

        $this->assertTrue($result->isHealthy());
        $this->assertSame(3, $callCount, 'Probe should retry until it gets a healthy response.');
    }

    public function test_probe_returns_last_failure_when_all_attempts_unhealthy(): void
    {
        $probe = new AppUrlProbe(function () {
            return new Response(502);
        });

        $result = $probe->probe('https://app.localhost', attempts: 3, retrySeconds: 0);

        $this->assertFalse($result->isHealthy());
        $this->assertSame(502, $result->statusCode);
    }

    public function test_probe_describes_unknown_failure_when_no_response_is_available(): void
    {
        $result = ProbeResult::failure('https://app.localhost', 'kapow');

        $this->assertFalse($result->isHealthy());
        $this->assertSame('Could not reach https://app.localhost — kapow', $result->describeFailure());
    }

    private function requesterReturning(Response $response): \Closure
    {
        return static fn () => $response;
    }

    private function requesterThrowing(\Throwable $e): \Closure
    {
        return static function () use ($e): never {
            throw $e;
        };
    }
}
