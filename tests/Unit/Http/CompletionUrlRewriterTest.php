<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Http;

use Ngramx\Http\CompletionUrlRewriter;
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
}
