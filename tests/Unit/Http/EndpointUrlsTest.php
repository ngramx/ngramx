<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Http;

use Ngramx\Config\Schema\DockerConfig;
use Ngramx\Config\Schema\EndpointConfig;
use Ngramx\Http\EndpointUrls;
use PHPUnit\Framework\TestCase;

class EndpointUrlsTest extends TestCase
{
    private function docker(): DockerConfig
    {
        return new DockerConfig(
            composeFile: '/proj/docker-compose.yml',
            primaryService: 'app',
            appUrl: 'http://earl-kendrick.localhost',
            endpoints: [
                'pwa' => new EndpointConfig(
                    name: 'pwa',
                    url: 'http://localhost:5173',
                    service: 'pwa',
                    env: ['VITE_API_BASE_URL' => '{url.primary}', 'EK_PWA_PORT' => '{port}'],
                    file: '.env',
                ),
                'api' => new EndpointConfig(
                    name: 'api',
                    url: 'https://api.earl-kendrick.localhost',
                    env: ['API_ORIGIN' => '{origin}', 'API_HOST' => '{host}'],
                    file: 'pwa/.env',
                ),
            ],
            env: ['REVERB_HOST' => '{host}', 'REVERB_PORT' => '{port}'],
        );
    }

    public function test_canonical_lists_primary_first_then_endpoints(): void
    {
        $urls = EndpointUrls::canonical($this->docker());

        $this->assertSame([
            'primary' => 'http://earl-kendrick.localhost',
            'pwa' => 'http://localhost:5173',
            'api' => 'https://api.earl-kendrick.localhost',
        ], $urls->all());
    }

    public function test_shifted_applies_offset_to_every_endpoint(): void
    {
        $urls = EndpointUrls::shifted($this->docker(), 200);

        $this->assertSame('http://earl-kendrick.localhost:280', $urls->primary);
        $this->assertSame('http://localhost:5373', $urls->get('pwa'));
        $this->assertSame('https://api.earl-kendrick.localhost:643', $urls->get('api'));
    }

    public function test_shifted_applies_port_map_when_no_offset(): void
    {
        $urls = EndpointUrls::shifted($this->docker(), 0, [5173 => 5180]);

        $this->assertSame('http://earl-kendrick.localhost', $urls->primary);
        $this->assertSame('http://localhost:5180', $urls->get('pwa'));
    }

    public function test_worktree_host_prefixes_endpoint_name(): void
    {
        $this->assertSame('gig-123-ek.localhost', EndpointUrls::worktreeHost('primary', 'gig-123-ek'));
        $this->assertSame('pwa.gig-123-ek.localhost', EndpointUrls::worktreeHost('pwa', 'gig-123-ek'));
    }

    public function test_expand_resolves_self_and_named_placeholders(): void
    {
        $urls = EndpointUrls::shifted($this->docker(), 200);

        $this->assertSame('http://earl-kendrick.localhost:280', $urls->expand('{url.primary}', 'pwa'));
        $this->assertSame('5373', $urls->expand('{port}', 'pwa'));
        $this->assertSame('earl-kendrick.localhost', $urls->expand('{host}'));
        $this->assertSame('280', $urls->expand('{port}', 'primary'));
        $this->assertSame('https://api.earl-kendrick.localhost:643', $urls->expand('{origin.api}'));
        $this->assertSame('https', $urls->expand('{scheme.api}'));
    }

    public function test_expand_uses_scheme_default_port_when_url_has_none(): void
    {
        $urls = EndpointUrls::canonical($this->docker());

        $this->assertSame('80', $urls->expand('{port}'));
        $this->assertSame('443', $urls->expand('{port.api}'));
    }

    public function test_expand_leaves_unknown_placeholders_visible(): void
    {
        $urls = EndpointUrls::canonical($this->docker());

        $this->assertSame('{url.nope} {bogus}', $urls->expand('{url.nope} {bogus}'));
    }

    public function test_env_files_groups_expanded_vars_by_file(): void
    {
        $files = EndpointUrls::shifted($this->docker(), 200)->envFiles($this->docker());

        $this->assertSame([
            '.env' => [
                'REVERB_HOST' => 'earl-kendrick.localhost',
                'REVERB_PORT' => '280',
                'VITE_API_BASE_URL' => 'http://earl-kendrick.localhost:280',
                'EK_PWA_PORT' => '5373',
            ],
            'pwa/.env' => [
                'API_ORIGIN' => 'https://api.earl-kendrick.localhost:643',
                'API_HOST' => 'api.earl-kendrick.localhost',
            ],
        ], $files);
    }

    public function test_from_recorded_prefers_lock_urls_but_ignores_unknown_names(): void
    {
        $fallback = EndpointUrls::shifted($this->docker(), 200);
        $urls = EndpointUrls::fromRecorded(
            'http://gig-1-ek.localhost:280',
            ['pwa' => 'http://pwa.gig-1-ek.localhost:5373', 'gone' => 'http://x'],
            $fallback,
        );

        $this->assertSame('http://gig-1-ek.localhost:280', $urls->primary);
        $this->assertSame('http://pwa.gig-1-ek.localhost:5373', $urls->get('pwa'));
        $this->assertSame('https://api.earl-kendrick.localhost:643', $urls->get('api'));
        $this->assertNull($urls->get('gone'));
    }

    public function test_from_recorded_falls_back_to_primary_when_lock_has_none(): void
    {
        $fallback = EndpointUrls::shifted($this->docker(), 200);

        $this->assertSame($fallback->primary, EndpointUrls::fromRecorded(null, [], $fallback)->primary);
    }
}
