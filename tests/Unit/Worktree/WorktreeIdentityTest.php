<?php

declare(strict_types=1);

namespace Cortex\Tests\Unit\Worktree;

use Cortex\Worktree\WorktreeIdentity;
use PHPUnit\Framework\TestCase;

class WorktreeIdentityTest extends TestCase
{
    public function test_it_derives_slug_from_branch_prefix(): void
    {
        $this->assertSame('gig-178', WorktreeIdentity::deriveTicketSlug('gig-178-pwa-rebranding', '178'));
        $this->assertSame('gig-1603', WorktreeIdentity::deriveTicketSlug('GIG-1603-some-title', 'GIG-1603'));
        $this->assertSame('cor-42', WorktreeIdentity::deriveTicketSlug('cor-42', 'cor-42'));
    }

    public function test_it_falls_back_to_ticket_when_branch_has_no_prefix(): void
    {
        $this->assertSame('hotfix-login', WorktreeIdentity::deriveTicketSlug('hotfix/login', 'hotfix/login'));
    }

    public function test_it_sanitizes_segments(): void
    {
        $this->assertSame('ill-kendrick', WorktreeIdentity::sanitizeSegment('Ill-Kendrick'));
        $this->assertSame('my-repo', WorktreeIdentity::sanitizeSegment('My_Repo!'));
        $this->assertSame('repo', WorktreeIdentity::sanitizeSegment('--repo--'));
    }

    public function test_it_builds_folder_name(): void
    {
        $this->assertSame('gig-178-ill-kendrick', WorktreeIdentity::folderName('gig-178', 'ill-kendrick'));
    }

    public function test_it_builds_namespace_with_prefix(): void
    {
        $this->assertSame('cortex-gig-178-ill-kendrick', WorktreeIdentity::namespaceFor('gig-178-ill-kendrick'));
    }

    public function test_it_truncates_long_namespace_to_63_chars(): void
    {
        $folder = 'gig-178-' . str_repeat('a', 80);
        $namespace = WorktreeIdentity::namespaceFor($folder);

        $this->assertLessThanOrEqual(63, strlen($namespace));
        $this->assertStringStartsWith('cortex-gig-178-', $namespace);
    }

    public function test_it_builds_url_with_subdomain_and_port_offset(): void
    {
        $url = WorktreeIdentity::buildUrl('https://myapp.localhost', 'gig-178-ill-kendrick', 8000);

        $this->assertSame('https://gig-178-ill-kendrick.localhost:8443', $url);
    }

    public function test_it_builds_url_from_http_without_explicit_port(): void
    {
        $url = WorktreeIdentity::buildUrl('http://localhost', 'gig-178-repo', 8000);

        $this->assertSame('http://gig-178-repo.localhost:8080', $url);
    }

    public function test_it_omits_port_when_offset_is_zero_and_no_base_port(): void
    {
        $url = WorktreeIdentity::buildUrl('http://localhost', 'gig-178-repo', 0);

        $this->assertSame('http://gig-178-repo.localhost', $url);
    }

    public function test_it_preserves_explicit_base_port_with_offset(): void
    {
        $url = WorktreeIdentity::buildUrl('http://localhost:8025', 'gig-1-repo', 100);

        $this->assertSame('http://gig-1-repo.localhost:8125', $url);
    }

    public function test_it_preserves_path(): void
    {
        $url = WorktreeIdentity::buildUrl('https://myapp.localhost/app', 'gig-1-repo', 0);

        $this->assertSame('https://gig-1-repo.localhost/app', $url);
    }
}
