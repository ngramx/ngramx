<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Http;

use Ngramx\Http\UrlPortOffset;
use PHPUnit\Framework\TestCase;

class UrlPortOffsetTest extends TestCase
{
    public function test_offset_zero_returns_url_unchanged(): void
    {
        $url = 'https://virginland.gigabyte.localhost';

        $this->assertSame($url, UrlPortOffset::apply($url, 0));
    }

    public function test_negative_offset_is_ignored(): void
    {
        $url = 'https://app.localhost:443';

        $this->assertSame($url, UrlPortOffset::apply($url, -100));
    }

    public function test_applies_offset_to_implicit_https_port(): void
    {
        $this->assertSame(
            'https://virginland.gigabyte.localhost:8543',
            UrlPortOffset::apply('https://virginland.gigabyte.localhost', 8100),
        );
    }

    public function test_applies_offset_to_implicit_http_port(): void
    {
        $this->assertSame(
            'http://app.localhost:8180',
            UrlPortOffset::apply('http://app.localhost', 8100),
        );
    }

    public function test_applies_offset_to_explicit_port(): void
    {
        $this->assertSame(
            'https://app.localhost:1543',
            UrlPortOffset::apply('https://app.localhost:443', 1100),
        );
    }

    public function test_preserves_path_query_and_fragment(): void
    {
        $this->assertSame(
            'https://app.localhost:1543/dashboard?foo=bar#section',
            UrlPortOffset::apply('https://app.localhost/dashboard?foo=bar#section', 1100),
        );
    }

    public function test_returns_unchanged_for_unsupported_scheme(): void
    {
        $url = 'ftp://files.localhost';

        // No default port mapping known => leave it alone rather than guessing.
        $this->assertSame($url, UrlPortOffset::apply($url, 100));
    }

    public function test_returns_unchanged_for_unparseable_url(): void
    {
        $this->assertSame('not a url', UrlPortOffset::apply('not a url', 100));
    }

    public function test_apply_map_swaps_scheme_default_port_when_mapped(): void
    {
        $this->assertSame(
            'http://app.localhost:180',
            UrlPortOffset::applyMap('http://app.localhost', [80 => 180]),
        );
    }

    public function test_apply_map_swaps_explicit_port_when_mapped(): void
    {
        $this->assertSame(
            'https://app.localhost:8543/dashboard?foo=bar#section',
            UrlPortOffset::applyMap('https://app.localhost:443/dashboard?foo=bar#section', [443 => 8543]),
        );
    }

    public function test_apply_map_leaves_url_unchanged_when_port_not_mapped(): void
    {
        $url = 'http://app.localhost';

        // Only the db port conflicted; the web URL must stay on its port.
        $this->assertSame($url, UrlPortOffset::applyMap($url, [5432 => 5532]));
    }

    public function test_apply_map_with_empty_map_returns_url_unchanged(): void
    {
        $url = 'https://app.localhost:443';

        $this->assertSame($url, UrlPortOffset::applyMap($url, []));
    }
}
