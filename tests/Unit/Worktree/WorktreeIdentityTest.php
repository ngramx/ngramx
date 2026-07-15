<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Worktree;

use Ngramx\Worktree\WorktreeIdentity;
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
        $this->assertSame('ngramx-gig-178-ill-kendrick', WorktreeIdentity::namespaceFor('gig-178-ill-kendrick'));
    }

    public function test_it_normalizes_bare_numbers_with_the_default_team(): void
    {
        $this->assertSame('gig-2345', WorktreeIdentity::normalizeTicket('2345', 'gig'));
        $this->assertSame('cor-99', WorktreeIdentity::normalizeTicket('99', 'cor'));
    }

    public function test_it_normalizes_full_ticket_references(): void
    {
        $this->assertSame('gig-2345', WorktreeIdentity::normalizeTicket('gig-2345', 'gig'));
        $this->assertSame('gig-2345', WorktreeIdentity::normalizeTicket('GIG-2345', 'gig'));
        $this->assertSame('gig-2345', WorktreeIdentity::normalizeTicket('gig2345', 'gig'));
        $this->assertSame('cor-268', WorktreeIdentity::normalizeTicket('cor268', 'gig'));
    }

    public function test_it_sanitizes_non_ticket_input(): void
    {
        $this->assertSame('hotfix-login', WorktreeIdentity::normalizeTicket('hotfix/login', 'gig'));
        $this->assertSame('ticket', WorktreeIdentity::normalizeTicket('!!', 'gig'));
    }

    public function test_it_truncates_long_namespace_to_63_chars(): void
    {
        $folder = 'gig-178-' . str_repeat('a', 80);
        $namespace = WorktreeIdentity::namespaceFor($folder);

        $this->assertLessThanOrEqual(63, strlen($namespace));
        $this->assertStringStartsWith('ngramx-gig-178-', $namespace);
    }
}
