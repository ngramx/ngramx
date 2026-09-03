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
    public function test_it_prefixes_named_endpoints_onto_the_worktree_host(): void
    {
        $resolver = $this->resolverReturning(static fn (string $host): int => 200);

        $url = $resolver->resolve('http://localhost:5173', 'gig-178-repo', 200, 'pwa');

        $this->assertSame('http://pwa.gig-178-repo.localhost:5373', $url);
    }

    public function test_named_endpoint_falls_back_to_its_own_host_when_host_routed(): void
    {
        $resolver = $this->resolverReturning(static fn (string $host): int => $host === 'api.myapp.test' ? 200 : 404);

        $url = $resolver->resolve('http://api.myapp.test', 'gig-178-repo', 8000, 'api');

        $this->assertSame('http://api.myapp.test:8080', $url);
    }

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

    public function test_it_keeps_the_apps_own_host_when_the_baseline_is_a_client_error(): void
    {
        // Hydra's API vhost 404s on "/", and an unrouted hostname 404s too. The
        // statuses match, but for unrelated reasons -- upgrading on that
        // evidence advertised an origin that served nothing.
        $resolver = $this->resolverReturning(static fn (string $host): int => 404);

        $url = $resolver->resolve('http://dev.api.hydra', 'gig-3054-hydra-main', 8100, 'api');

        $this->assertSame('http://dev.api.hydra:8180', $url);
    }

    public function test_it_keeps_the_apps_own_host_when_the_baseline_is_unreachable(): void
    {
        $resolver = $this->resolverReturning(static fn (string $host): ?int => null);

        $url = $resolver->resolve('http://dev.hydra', 'gig-3054-hydra-main', 8100);

        $this->assertSame('http://dev.hydra:8180', $url);
    }

    public function test_a_redirecting_baseline_still_upgrades_a_host_agnostic_app(): void
    {
        // 3xx is the app serving (Laravel redirecting to /login), so an equal
        // status on the invented subdomain is real evidence of host-agnosticism.
        $resolver = $this->resolverReturning(static fn (string $host): int => 302);

        $url = $resolver->resolve('http://earl-kendrick.localhost', 'gig-9001-ek', 8000);

        $this->assertSame('http://gig-9001-ek.localhost:8080', $url);
    }

    /**
     * Build a resolver whose probe returns a status code derived from the
     * request's Host header. Returning null simulates a refused connection.
     *
     * @param callable(string): (int|null) $statusForHost
     */
    private function resolverReturning(callable $statusForHost): WorktreeUrlResolver
    {
        $probe = new AppUrlProbe(static function (string $method, string $url, array $options) use ($statusForHost): Response {
            $host = (string) ($options['headers']['Host'] ?? '');
            $status = $statusForHost($host);
            if ($status === null) {
                throw new ConnectException('refused', new Request($method, $url));
            }

            return new Response($status);
        });

        return new WorktreeUrlResolver($probe, baselineAttempts: 1);
    }
}
