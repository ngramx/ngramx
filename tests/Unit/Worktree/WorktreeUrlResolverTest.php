<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Worktree;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Ngramx\Http\AppUrlProbe;
use Ngramx\Worktree\WorktreeUrlResolver;
use PHPUnit\Framework\TestCase;

class WorktreeUrlResolverTest extends TestCase
{
    public function test_it_uses_subdomain_when_app_is_host_agnostic(): void
    {
        // Same status regardless of the Host header -> the app ignores Host.
        $resolver = $this->resolverReturning(static fn (string $host): int => 200);

        $url = $resolver->resolve('http://myapp.test', 'gig-178-repo', 8000);

        $this->assertSame('http://gig-178-repo.localhost:8080', $url);
    }

    public function test_it_falls_back_to_real_host_when_app_routes_by_host(): void
    {
        // 302 for the configured host, 404 for anything else -> name-based vhost.
        $resolver = $this->resolverReturning(
            static fn (string $host): int => $host === 'dev.hydra' ? 302 : 404
        );

        $url = $resolver->resolve('http://dev.hydra', 'gig-2301-hydra-main', 8000);

        $this->assertSame('http://dev.hydra:8080', $url);
    }

    public function test_it_preserves_path_when_choosing_subdomain(): void
    {
        $resolver = $this->resolverReturning(static fn (string $host): int => 200);

        $url = $resolver->resolve('https://myapp.test/app', 'gig-1-repo', 8000);

        $this->assertSame('https://gig-1-repo.localhost:8443/app', $url);
    }

    public function test_it_falls_back_to_real_host_when_app_is_unreachable(): void
    {
        $probe = new AppUrlProbe(static function (): Response {
            throw new ConnectException('down', new Request('GET', '/'));
        });
        // baselineAttempts=1 keeps the unreachable path from sleeping between retries.
        $resolver = new WorktreeUrlResolver($probe, baselineAttempts: 1);

        $url = $resolver->resolve('http://dev.hydra', 'gig-1-repo', 8000);

        $this->assertSame('http://dev.hydra:8080', $url);
    }

    public function test_it_keeps_app_url_when_it_is_already_a_localhost_subdomain(): void
    {
        // No probe should be needed; throw if one is attempted.
        $probe = new AppUrlProbe(static function (): Response {
            throw new \RuntimeException('should not probe');
        });
        $resolver = new WorktreeUrlResolver($probe, baselineAttempts: 1);

        $url = $resolver->resolve('http://app.localhost', 'gig-9-repo', 8000);

        $this->assertSame('http://app.localhost:8080', $url);
    }

    /**
     * Build a resolver whose probe returns a status code derived from the
     * request's Host header.
     *
     * @param callable(string): int $statusForHost
     */
    private function resolverReturning(callable $statusForHost): WorktreeUrlResolver
    {
        $probe = new AppUrlProbe(static function (string $method, string $url, array $options) use ($statusForHost): Response {
            $host = (string) ($options['headers']['Host'] ?? '');

            return new Response($statusForHost($host));
        });

        return new WorktreeUrlResolver($probe, baselineAttempts: 1);
    }
}
