<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Http;

use Ngramx\Http\CompletionUrlRewriter;
use Ngramx\Http\EndpointUrls;
use PHPUnit\Framework\TestCase;

class CompletionUrlRewriterTest extends TestCase
{
    public function test_swaps_host_and_port_onto_worktree_environment(): void
    {
        $this->assertSame(
            'https://741-virginland.localhost:8743/v/developers',
            CompletionUrlRewriter::rewrite(
                'https://app.localhost/v/developers',
                'https://741-virginland.localhost:8743',
            ),
        );
    }

    public function test_preserves_path_query_and_fragment(): void
    {
        $this->assertSame(
            'https://741-virginland.localhost:8743/invoices/INV-0042?bypass=hello@example.com#totals',
            CompletionUrlRewriter::rewrite(
                'https://app.localhost/invoices/INV-0042?bypass=hello@example.com#totals',
                'https://741-virginland.localhost:8743',
            ),
        );
    }

    public function test_drops_port_when_environment_has_no_explicit_port(): void
    {
        $this->assertSame(
            'https://app.localhost/v/developers',
            CompletionUrlRewriter::rewrite(
                'https://app.localhost:8743/v/developers',
                'https://app.localhost',
            ),
        );
    }

    public function test_swaps_scheme_to_match_environment(): void
    {
        $this->assertSame(
            'http://741-virginland.localhost:8080/dashboard',
            CompletionUrlRewriter::rewrite(
                'https://app.localhost/dashboard',
                'http://741-virginland.localhost:8080',
            ),
        );
    }

    public function test_root_path_url_is_rewritten(): void
    {
        $this->assertSame(
            'https://741-virginland.localhost:8743',
            CompletionUrlRewriter::rewrite(
                'https://app.localhost',
                'https://741-virginland.localhost:8743',
            ),
        );
    }

    public function test_non_http_url_is_left_untouched(): void
    {
        $this->assertSame(
            'mailto:hello@example.com',
            CompletionUrlRewriter::rewrite(
                'mailto:hello@example.com',
                'https://741-virginland.localhost:8743',
            ),
        );
    }

    public function test_unparseable_url_is_left_untouched(): void
    {
        $this->assertSame(
            'not a url',
            CompletionUrlRewriter::rewrite('not a url', 'https://741-virginland.localhost:8743'),
        );
    }

    public function test_url_without_host_is_left_untouched(): void
    {
        $this->assertSame(
            '/v/developers',
            CompletionUrlRewriter::rewrite('/v/developers', 'https://741-virginland.localhost:8743'),
        );
    }

    public function test_returns_original_when_base_url_is_unusable(): void
    {
        $this->assertSame(
            'https://app.localhost/v/developers',
            CompletionUrlRewriter::rewrite('https://app.localhost/v/developers', 'not a url'),
        );
    }

    private function canonical(): EndpointUrls
    {
        return new EndpointUrls('http://earl-kendrick.localhost', [
            'pwa' => 'http://localhost:5173',
            'preview' => 'http://localhost:4173',
            'api' => 'http://api.earl-kendrick.localhost',
        ]);
    }

    private function live(): EndpointUrls
    {
        return new EndpointUrls('http://gig-1-ek.localhost:280', [
            'pwa' => 'http://pwa.gig-1-ek.localhost:5373',
            'preview' => 'http://preview.gig-1-ek.localhost:4373',
            'api' => 'http://api.gig-1-ek.localhost:280',
        ]);
    }

    public function test_endpoint_rewrite_follows_matching_endpoint_not_primary(): void
    {
        $this->assertSame(
            'http://pwa.gig-1-ek.localhost:5373/surveys/1?x=1',
            CompletionUrlRewriter::rewriteEndpoints('http://localhost:5173/surveys/1?x=1', $this->canonical(), $this->live()),
        );
    }

    public function test_endpoint_rewrite_distinguishes_same_host_by_port(): void
    {
        $this->assertSame(
            'http://preview.gig-1-ek.localhost:4373/',
            CompletionUrlRewriter::rewriteEndpoints('http://localhost:4173/', $this->canonical(), $this->live()),
        );
    }

    public function test_endpoint_rewrite_matches_by_host_when_port_differs(): void
    {
        // A link written against the API host on some other port still means the API.
        $this->assertSame(
            'http://api.gig-1-ek.localhost:280/v1/jobs',
            CompletionUrlRewriter::rewriteEndpoints('http://api.earl-kendrick.localhost:9999/v1/jobs', $this->canonical(), $this->live()),
        );
    }

    public function test_endpoint_rewrite_falls_back_to_primary_for_unknown_hosts(): void
    {
        $this->assertSame(
            'http://gig-1-ek.localhost:280/admin',
            CompletionUrlRewriter::rewriteEndpoints('https://app.localhost/admin', $this->canonical(), $this->live()),
        );
    }
}
