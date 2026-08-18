<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Worktree;

use Ngramx\Worktree\WorktreeMatcher;
use PHPUnit\Framework\TestCase;

class WorktreeMatcherTest extends TestCase
{
    private const ROOT = '/repo/.ngramx/worktrees/';

    /** @var list<string> */
    private array $worktrees = [
        self::ROOT . 'gig-2478-terrablock',
        self::ROOT . 'gig-2895-terrablock',
        self::ROOT . 'gig-2896-terrablock',
    ];

    /** @var array<string, string> */
    private array $branches = [
        self::ROOT . 'gig-2478-terrablock' => 'gig-2478-invoice-pdf',
        self::ROOT . 'gig-2895-terrablock' => 'gig-2895-audit-log',
        self::ROOT . 'gig-2896-terrablock' => 'cursor/gig-2896-show-url',
    ];

    public function test_it_matches_an_exact_folder_name(): void
    {
        $this->assertSame(
            [self::ROOT . 'gig-2895-terrablock'],
            WorktreeMatcher::match($this->worktrees, $this->branches, 'gig-2895-terrablock'),
        );
    }

    public function test_it_matches_a_docker_namespace(): void
    {
        $this->assertSame(
            [self::ROOT . 'gig-2896-terrablock'],
            WorktreeMatcher::match($this->worktrees, $this->branches, 'ngramx-gig-2896-terrablock'),
        );
    }

    public function test_it_matches_a_ticket_reference_in_any_spelling(): void
    {
        foreach (['gig-2478', 'GIG-2478', 'gig2478', '2478'] as $needle) {
            $this->assertSame(
                [self::ROOT . 'gig-2478-terrablock'],
                WorktreeMatcher::match($this->worktrees, $this->branches, $needle, 'gig'),
                "spelling: $needle",
            );
        }
    }

    public function test_it_matches_a_ticket_whose_folder_lacks_the_team_prefix(): void
    {
        // Folder names come from the branch: "2478-fix" => "2478-<repo>".
        $worktrees = [self::ROOT . '2478-terrablock'];

        $this->assertSame(
            [self::ROOT . '2478-terrablock'],
            WorktreeMatcher::match($worktrees, [], 'gig-2478', 'gig'),
        );
    }

    public function test_it_matches_a_fragment_of_the_branch_name(): void
    {
        $this->assertSame(
            [self::ROOT . 'gig-2895-terrablock'],
            WorktreeMatcher::match($this->worktrees, $this->branches, 'audit-log'),
        );
    }

    public function test_it_returns_every_worktree_an_ambiguous_fragment_matches(): void
    {
        $this->assertSame(
            $this->worktrees,
            WorktreeMatcher::match($this->worktrees, $this->branches, 'terrablock'),
        );
    }

    public function test_an_exact_name_wins_over_a_substring(): void
    {
        $worktrees = [
            self::ROOT . 'gig-24',
            self::ROOT . 'gig-240-thing',
        ];

        $this->assertSame(
            [self::ROOT . 'gig-24'],
            WorktreeMatcher::match($worktrees, [], 'gig-24'),
        );
    }

    public function test_it_returns_nothing_when_nothing_matches(): void
    {
        $this->assertSame([], WorktreeMatcher::match($this->worktrees, $this->branches, 'nope-9999'));
        $this->assertSame([], WorktreeMatcher::match($this->worktrees, $this->branches, '   '));
    }
}
