<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Git;

use Ngramx\Git\GitRepositoryService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\BufferedOutput;

class GitRepositoryServiceTest extends TestCase
{
    private GitRepositoryService $service;
    private string $tempDir;
    private string $gitRepoPath;

    protected function setUp(): void
    {
        $this->service = new GitRepositoryService();
        $this->tempDir = sys_get_temp_dir() . '/ngramx-git-test-' . uniqid();
        $bareRepoPath = $this->tempDir . '/bare-repo';
        $this->gitRepoPath = $this->tempDir . '/repo';

        // Create temporary directories
        mkdir($this->tempDir, 0755, true);
        mkdir($this->gitRepoPath, 0755, true);
        mkdir($bareRepoPath, 0755, true);

        // Create bare repository as remote
        $this->runCommand('git init --bare', $bareRepoPath);

        // Initialize git repository
        $this->runGitCommand('git init');
        $this->runGitCommand('git config user.name "Test User"');
        $this->runGitCommand('git config user.email "test@example.com"');

        // Create initial commit
        file_put_contents($this->gitRepoPath . '/README.md', '# Test Repository');
        $this->runGitCommand('git add README.md');
        $this->runGitCommand('git commit -m "Initial commit"');
        $this->runGitCommand('git branch -M main');

        // Set up remote
        $this->runGitCommand('git remote add origin ' . escapeshellarg($bareRepoPath));
        $this->runGitCommand('git push -u origin main');

        // Create test branches with different commit dates
        $this->createBranchWithCommit('feature/TICKET-123', '2024-01-01 10:00:00', 'Commit for TICKET-123');
        $this->createBranchWithCommit('feature/TICKET-456', '2024-01-02 10:00:00', 'Commit for TICKET-456');
        $this->createBranchWithCommit('bugfix/TICKET-123-fix', '2024-01-03 10:00:00', 'Fix for TICKET-123');
        $this->createBranchWithCommit('feature/other-branch', '2024-01-04 10:00:00', 'Other branch');

        // Push all branches to origin
        $this->runGitCommand('git push origin --all');

        // Fetch to create remote tracking branches
        $this->runGitCommand('git fetch origin');
    }

    protected function tearDown(): void
    {
        // Clean up temporary directory
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    public function test_findBranchesContaining_returns_matching_branches(): void
    {
        $branches = $this->service->findBranchesContaining($this->gitRepoPath, 'TICKET-123');

        $this->assertCount(2, $branches);
        $this->assertContains('feature/TICKET-123', $branches);
        $this->assertContains('bugfix/TICKET-123-fix', $branches);
    }

    public function test_findBranchesContaining_returns_empty_array_when_no_matches(): void
    {
        $branches = $this->service->findBranchesContaining($this->gitRepoPath, 'NONEXISTENT');

        $this->assertEmpty($branches);
    }

    public function test_findBranchesContaining_handles_special_characters(): void
    {
        $branches = $this->service->findBranchesContaining($this->gitRepoPath, 'feature');

        $this->assertNotEmpty($branches);
        $this->assertContains('feature/TICKET-123', $branches);
        $this->assertContains('feature/TICKET-456', $branches);
        $this->assertContains('feature/other-branch', $branches);
    }

    public function test_findBranchesContaining_removes_origin_prefix(): void
    {
        $branches = $this->service->findBranchesContaining($this->gitRepoPath, 'TICKET-123');

        foreach ($branches as $branch) {
            $this->assertFalse(str_starts_with($branch, 'origin/'), "Branch '{$branch}' should not start with 'origin/'");
        }
    }

    public function test_findBranchesContaining_removes_duplicates(): void
    {
        // Create a branch that might appear multiple times in grep results
        $this->createBranchWithCommit('feature/TICKET-123-duplicate', '2024-01-05 10:00:00', 'Duplicate test');

        $branches = $this->service->findBranchesContaining($this->gitRepoPath, 'TICKET-123');

        $uniqueBranches = array_unique($branches);
        $this->assertEquals(count($uniqueBranches), count($branches));
    }

    public function test_findLocalBranchesContaining_finds_local_only_branches(): void
    {
        $this->createBranchWithCommit(
            'gig-2497-allow-defect-pins-to-be-assigned-to-specific-custom-report',
            '2024-01-06 10:00:00',
            'Local-only feature branch'
        );

        $this->assertEmpty($this->service->findBranchesContaining($this->gitRepoPath, 'gig-2497'));
        $this->assertSame(
            ['gig-2497-allow-defect-pins-to-be-assigned-to-specific-custom-report'],
            $this->service->findLocalBranchesContaining($this->gitRepoPath, 'gig-2497')
        );
    }

    public function test_findBranchesForTicketPrefix_matches_exact_and_suffixed_branches(): void
    {
        $this->createBranchWithCommit('gig-2497', '2024-01-06 10:00:00', 'Exact ticket branch');
        $this->createBranchWithCommit(
            'gig-2497-allow-defect-pins-to-be-assigned-to-specific-custom-report',
            '2024-01-07 10:00:00',
            'Suffixed ticket branch'
        );
        $this->createBranchWithCommit('gig-24970-unrelated', '2024-01-08 10:00:00', 'Different ticket');

        $matches = $this->service->findBranchesForTicketPrefix($this->gitRepoPath, 'gig-2497');

        $this->assertContains('gig-2497', $matches);
        $this->assertContains('gig-2497-allow-defect-pins-to-be-assigned-to-specific-custom-report', $matches);
        $this->assertNotContains('gig-24970-unrelated', $matches);
    }

    public function test_mapCheckedOutBranches_maps_branch_to_worktree_path(): void
    {
        $worktreePath = $this->tempDir . '/wt-map-checked-out';
        $this->service->addWorktree($this->gitRepoPath, $worktreePath, 'feature/TICKET-456');

        $checkedOut = $this->service->mapCheckedOutBranches($this->gitRepoPath);

        $this->assertSame($worktreePath, $checkedOut['feature/TICKET-456']);
    }

    public function test_selectBranchForWorktree_auto_selects_single_available_branch(): void
    {
        $branch = 'gig-2497-allow-defect-pins-to-be-assigned-to-specific-custom-report';
        $this->createBranchWithCommit($branch, '2024-01-06 10:00:00', 'Local-only feature branch');

        $infoMessages = [];
        $input = $this->createStreamableInput('');
        $output = new BufferedOutput();

        $selected = $this->service->selectBranchForWorktree(
            $this->gitRepoPath,
            [$branch],
            $input,
            $output,
            function (string $message) use (&$infoMessages): void {
                $infoMessages[] = $message;
            },
            fn (string $message) => null,
        );

        $this->assertSame($branch, $selected);
        $this->assertSame(['Using branch: ' . $branch], $infoMessages);
    }

    public function test_selectBranchForWorktree_excludes_checked_out_branches(): void
    {
        $availableBranch = 'gig-2497-available';
        $checkedOutBranch = 'gig-2497-checked-out';
        $this->createBranchWithCommit($availableBranch, '2024-01-06 10:00:00', 'Available branch');
        $this->createBranchWithCommit($checkedOutBranch, '2024-01-07 10:00:00', 'Checked-out branch');

        $worktreePath = $this->tempDir . '/wt-checked-out-branch';
        $this->service->addWorktree($this->gitRepoPath, $worktreePath, $checkedOutBranch);

        $warningMessages = [];
        $input = $this->createStreamableInput('');
        $output = new BufferedOutput();

        $selected = $this->service->selectBranchForWorktree(
            $this->gitRepoPath,
            [$availableBranch, $checkedOutBranch],
            $input,
            $output,
            fn (string $message) => null,
            function (string $message) use (&$warningMessages): void {
                $warningMessages[] = $message;
            },
        );

        $this->assertSame($availableBranch, $selected);
        $this->assertStringContainsString('already checked out elsewhere', implode("\n", $warningMessages));
        $this->assertStringContainsString($checkedOutBranch, implode("\n", $warningMessages));
        $this->assertStringContainsString($worktreePath, implode("\n", $warningMessages));
    }

    public function test_selectBranchForWorktree_throws_when_all_matches_are_checked_out(): void
    {
        $branch = 'gig-2497-only-checked-out';
        $this->createBranchWithCommit($branch, '2024-01-06 10:00:00', 'Checked-out branch');

        $worktreePath = $this->tempDir . '/wt-only-checked-out';
        $this->service->addWorktree($this->gitRepoPath, $worktreePath, $branch);

        $input = $this->createStreamableInput('');
        $output = new BufferedOutput();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('All matching branches are already checked out in other worktrees');

        $this->service->selectBranchForWorktree(
            $this->gitRepoPath,
            [$branch],
            $input,
            $output,
            fn (string $message) => null,
            fn (string $message) => null,
        );
    }

    public function test_findMostRecentBranch_returns_most_recent_branch(): void
    {
        $branches = ['feature/TICKET-123', 'feature/TICKET-456', 'bugfix/TICKET-123-fix'];

        $mostRecent = $this->service->findMostRecentBranch($this->gitRepoPath, $branches);

        // bugfix/TICKET-123-fix was created last (2024-01-03)
        $this->assertEquals('bugfix/TICKET-123-fix', $mostRecent);
    }

    public function test_findMostRecentBranch_throws_exception_for_empty_array(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No branches found');

        $this->service->findMostRecentBranch($this->gitRepoPath, []);
    }

    public function test_findMostRecentBranch_returns_first_branch_when_cannot_determine_dates(): void
    {
        // Use an empty directory (not a git repo) to simulate git log failures
        $emptyDir = $this->tempDir . '/empty-dir';
        mkdir($emptyDir, 0755, true);
        $branches = ['branch1', 'branch2'];

        $result = $this->service->findMostRecentBranch($emptyDir, $branches);

        // Should return first branch as fallback when git log fails
        $this->assertEquals('branch1', $result);
    }

    public function test_findMostRecentBranch_handles_single_branch(): void
    {
        $branches = ['feature/TICKET-123'];

        $mostRecent = $this->service->findMostRecentBranch($this->gitRepoPath, $branches);

        $this->assertEquals('feature/TICKET-123', $mostRecent);
    }

    public function test_selectBranch_returns_single_branch_without_prompting(): void
    {
        $branches = ['feature/TICKET-123'];
        $infoMessages = [];
        $warningMessages = [];

        $input = $this->createStreamableInput('');
        $output = new BufferedOutput();

        $selected = $this->service->selectBranch(
            $this->gitRepoPath,
            $branches,
            $input,
            $output,
            function (string $message) use (&$infoMessages) {
                $infoMessages[] = $message;
            },
            function (string $message) use (&$warningMessages) {
                $warningMessages[] = $message;
            }
        );

        $this->assertEquals('feature/TICKET-123', $selected);
        $this->assertCount(1, $infoMessages);
        $this->assertStringContainsString('Found single branch', $infoMessages[0]);
    }

    public function test_selectBranch_uses_most_recent_as_default(): void
    {
        $branches = ['feature/TICKET-123', 'feature/TICKET-456', 'bugfix/TICKET-123-fix'];
        $infoMessages = [];

        // Provide input that selects the default (first option, index 0)
        // For ChoiceQuestion, we provide the answer as the branch name
        $input = $this->createStreamableInput("bugfix/TICKET-123-fix\n");
        $output = new BufferedOutput();

        $selected = $this->service->selectBranch(
            $this->gitRepoPath,
            $branches,
            $input,
            $output,
            function (string $message) use (&$infoMessages) {
                $infoMessages[] = $message;
            },
            fn (string $message) => null,
            null
        );

        // Should return the most recent branch (bugfix/TICKET-123-fix)
        $this->assertEquals('bugfix/TICKET-123-fix', $selected);
        $this->assertStringContainsString('Found 3 branches', $infoMessages[0]);
    }

    public function test_selectBranch_applies_preference_callback(): void
    {
        $branches = ['feature/TICKET-123', 'feature/TICKET-456', 'bugfix/TICKET-123-fix'];
        $infoMessages = [];

        // Provide input selecting a feature branch
        $input = $this->createStreamableInput("feature/TICKET-123\n");
        $output = new BufferedOutput();

        // Preference callback: prefer branches starting with "feature/"
        $preferenceCallback = fn (string $branch): bool => str_starts_with($branch, 'feature/');

        $selected = $this->service->selectBranch(
            $this->gitRepoPath,
            $branches,
            $input,
            $output,
            function (string $message) use (&$infoMessages) {
                $infoMessages[] = $message;
            },
            fn (string $message) => null,
            $preferenceCallback
        );

        // Should prefer a feature branch even if bugfix is more recent
        // The most recent is bugfix/TICKET-123-fix, but preference should override
        $this->assertContains($selected, $branches);
        // Verify preference was applied - default should be a feature branch
        $outputContent = $output->fetch();
        $this->assertStringContainsString('feature/', $outputContent);
    }

    public function test_selectBranch_handles_exception_from_findMostRecentBranch(): void
    {
        $branches = ['branch1', 'branch2'];
        $infoMessages = [];

        $input = $this->createStreamableInput("branch1\n");
        $output = new BufferedOutput();

        // Use non-existent path to trigger exception in findMostRecentBranch
        $nonExistentPath = '/nonexistent/repo/path';

        $selected = $this->service->selectBranch(
            $nonExistentPath,
            $branches,
            $input,
            $output,
            function (string $message) use (&$infoMessages) {
                $infoMessages[] = $message;
            },
            fn (string $message) => null
        );

        // Should fall back to first branch when exception occurs
        $this->assertEquals('branch1', $selected);
    }

    public function test_fetchFromOrigin_picks_up_newly_pushed_branches(): void
    {
        // Create a brand new branch on origin via a separate clone after setUp's fetch
        $this->createBranchOnOrigin('feature/NEW-TICKET-999', 'new-ticket.txt', 'Commit for NEW-TICKET-999');

        // Before fetch, origin/feature/NEW-TICKET-999 shouldn't be known locally
        $branchesBefore = $this->service->findBranchesContaining($this->gitRepoPath, 'NEW-TICKET-999');
        $this->assertNotContains('feature/NEW-TICKET-999', $branchesBefore);

        $result = $this->service->fetchFromOrigin($this->gitRepoPath);

        $this->assertTrue($result);
        $branchesAfter = $this->service->findBranchesContaining($this->gitRepoPath, 'NEW-TICKET-999');
        $this->assertContains('feature/NEW-TICKET-999', $branchesAfter);
    }

    public function test_fetchFromOrigin_prunes_branches_deleted_on_origin(): void
    {
        // Delete a branch from origin via a separate clone
        $this->deleteBranchOnOrigin('feature/other-branch');

        $result = $this->service->fetchFromOrigin($this->gitRepoPath);

        $this->assertTrue($result);
        $branches = $this->service->findBranchesContaining($this->gitRepoPath, 'other-branch');
        $this->assertNotContains('feature/other-branch', $branches, 'Pruned branch should no longer appear in remote-tracking refs');
    }

    public function test_checkoutBranch_creates_local_branch_from_origin_when_missing(): void
    {
        // Remove the local branch so only origin/<branch> exists
        $this->runGitCommand('git branch -D feature/TICKET-456');

        $result = $this->service->checkoutBranch($this->gitRepoPath, 'feature/TICKET-456');

        $this->assertTrue($result);
        $this->assertEquals('feature/TICKET-456', $this->currentBranch());
    }

    public function test_checkoutBranch_fast_forwards_existing_local_branch_to_origin(): void
    {
        // Advance origin with a new commit pushed from a separate clone
        $this->pushAdditionalCommitToOrigin('feature/TICKET-456', 'update-from-origin.txt', 'Second commit on TICKET-456');

        // Fetch so origin/<branch> in our repo knows about the new commit
        $this->runGitCommand('git fetch origin');

        $localTipBefore = $this->revParse('feature/TICKET-456');
        $originTip = $this->revParse('origin/feature/TICKET-456');
        $this->assertNotEquals($localTipBefore, $originTip, 'Precondition: local should be behind origin');

        $result = $this->service->checkoutBranch($this->gitRepoPath, 'feature/TICKET-456');

        $this->assertTrue($result);
        $this->assertEquals('feature/TICKET-456', $this->currentBranch());
        $this->assertEquals($originTip, $this->revParse('HEAD'), 'Local branch should be fast-forwarded to origin');
    }

    public function test_checkoutBranch_returns_false_when_local_branch_diverges_from_origin(): void
    {
        // Add a local-only commit on the existing local branch so it diverges from origin
        $this->runGitCommand('git checkout feature/TICKET-456');
        file_put_contents($this->gitRepoPath . '/local-only.txt', 'local changes');
        $this->runGitCommand('git add local-only.txt');
        $this->runGitCommand('git commit -m "Local-only commit"');
        $this->runGitCommand('git checkout main');

        // Push a different commit to origin so a fast-forward is impossible
        $this->pushAdditionalCommitToOrigin('feature/TICKET-456', 'origin-only.txt', 'Origin-only commit');
        $this->runGitCommand('git fetch origin');

        $result = $this->service->checkoutBranch($this->gitRepoPath, 'feature/TICKET-456');

        $this->assertFalse($result, 'Diverged local branch should fail fast-forward and return false');
    }

    public function test_selectBranch_outputs_branch_list(): void
    {
        $branches = ['feature/TICKET-123', 'feature/TICKET-456'];
        $infoMessages = [];

        $input = $this->createStreamableInput("feature/TICKET-123\n");
        $output = new BufferedOutput();

        $this->service->selectBranch(
            $this->gitRepoPath,
            $branches,
            $input,
            $output,
            function (string $message) use (&$infoMessages) {
                $infoMessages[] = $message;
            },
            fn (string $message) => null
        );

        $outputContent = $output->fetch();

        $this->assertStringContainsString('feature/TICKET-123', $outputContent);
        $this->assertStringContainsString('feature/TICKET-456', $outputContent);
    }

    /**
     * Create a streamable input for testing interactive questions
     */
    private function createStreamableInput(string $input): StreamableInputInterface
    {
        $stream = fopen('php://memory', 'r+', false);
        if ($stream === false) {
            throw new \RuntimeException('Failed to create stream for testing');
        }
        fwrite($stream, $input);
        rewind($stream);

        $arrayInput = new ArrayInput([]);
        $arrayInput->setStream($stream);

        return $arrayInput;
    }

    /**
     * Run a git command in the test repository
     */
    private function runGitCommand(string $command): void
    {
        $this->runCommand($command, $this->gitRepoPath);
    }

    /**
     * Run a command in a specific directory
     */
    private function runCommand(string $command, string $cwd): void
    {
        $process = \Symfony\Component\Process\Process::fromShellCommandline(
            $command,
            $cwd
        );
        $process->setTimeout(10);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "Command failed: {$command}\n" . $process->getErrorOutput()
            );
        }
    }

    /**
     * Create a branch with a commit at a specific date
     */
    private function createBranchWithCommit(string $branchName, string $date, string $message): void
    {
        // Get current branch name
        $currentBranchProcess = \Symfony\Component\Process\Process::fromShellCommandline(
            'git branch --show-current',
            $this->gitRepoPath
        );
        $currentBranchProcess->run();
        $currentBranch = trim($currentBranchProcess->getOutput()) ?: 'main';

        // Create and checkout branch
        $this->runGitCommand("git checkout -b {$branchName}");
        // Sanitize branch name for filename (replace slashes with dashes)
        $filename = str_replace('/', '-', $branchName) . '.txt';
        file_put_contents($this->gitRepoPath . "/{$filename}", "Content for {$branchName}");
        $this->runGitCommand("git add {$filename}");

        // Set GIT_AUTHOR_DATE and GIT_COMMITTER_DATE to control commit date
        $env = [
            'GIT_AUTHOR_DATE' => $date,
            'GIT_COMMITTER_DATE' => $date,
        ];

        $process = \Symfony\Component\Process\Process::fromShellCommandline(
            'git commit -m ' . escapeshellarg($message),
            $this->gitRepoPath,
            $env
        );
        $process->setTimeout(10);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "Failed to create commit: {$message}\n" . $process->getErrorOutput()
            );
        }

        // Return to original branch
        $this->runGitCommand("git checkout {$currentBranch}");
    }

    /**
     * Return the currently checked-out branch name in the test repo
     */
    private function currentBranch(): string
    {
        $process = \Symfony\Component\Process\Process::fromShellCommandline(
            'git branch --show-current',
            $this->gitRepoPath
        );
        $process->setTimeout(10);
        $process->run();

        return trim($process->getOutput());
    }

    /**
     * Return the commit SHA that a given ref points at in the test repo
     */
    private function revParse(string $ref): string
    {
        $process = \Symfony\Component\Process\Process::fromShellCommandline(
            'git rev-parse ' . escapeshellarg($ref),
            $this->gitRepoPath
        );
        $process->setTimeout(10);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException("Failed to rev-parse {$ref}: " . $process->getErrorOutput());
        }

        return trim($process->getOutput());
    }

    /**
     * Create a brand-new branch on origin via a separate clone, so the test repo
     * only learns about it after an explicit fetch.
     */
    private function createBranchOnOrigin(string $branch, string $filename, string $message): void
    {
        $clonePath = $this->tempDir . '/clone-' . uniqid();
        $this->runCommand(
            'git clone ' . escapeshellarg($this->tempDir . '/bare-repo') . ' ' . escapeshellarg($clonePath),
            $this->tempDir
        );
        $this->runCommand('git config user.name "Test User"', $clonePath);
        $this->runCommand('git config user.email "test@example.com"', $clonePath);
        $this->runCommand('git checkout -b ' . escapeshellarg($branch), $clonePath);

        file_put_contents($clonePath . '/' . $filename, "Content for {$branch}");
        $this->runCommand('git add ' . escapeshellarg($filename), $clonePath);
        $this->runCommand('git commit -m ' . escapeshellarg($message), $clonePath);
        $this->runCommand('git push -u origin ' . escapeshellarg($branch), $clonePath);
    }

    /**
     * Delete a branch from origin via a separate clone, simulating a teammate
     * removing a branch after merge.
     */
    private function deleteBranchOnOrigin(string $branch): void
    {
        $clonePath = $this->tempDir . '/clone-' . uniqid();
        $this->runCommand(
            'git clone ' . escapeshellarg($this->tempDir . '/bare-repo') . ' ' . escapeshellarg($clonePath),
            $this->tempDir
        );
        $this->runCommand('git push origin --delete ' . escapeshellarg($branch), $clonePath);
    }

    /**
     * Push an additional commit onto an existing origin branch via a separate clone,
     * simulating a teammate updating the branch while the test repo stays untouched.
     */
    private function pushAdditionalCommitToOrigin(string $branch, string $filename, string $message): void
    {
        $clonePath = $this->tempDir . '/clone-' . uniqid();
        $this->runCommand(
            'git clone ' . escapeshellarg($this->tempDir . '/bare-repo') . ' ' . escapeshellarg($clonePath),
            $this->tempDir
        );
        $this->runCommand('git config user.name "Test User"', $clonePath);
        $this->runCommand('git config user.email "test@example.com"', $clonePath);
        $this->runCommand('git checkout ' . escapeshellarg($branch), $clonePath);

        file_put_contents($clonePath . '/' . $filename, "Additional content for {$branch}");
        $this->runCommand('git add ' . escapeshellarg($filename), $clonePath);
        $this->runCommand('git commit -m ' . escapeshellarg($message), $clonePath);
        $this->runCommand('git push origin ' . escapeshellarg($branch), $clonePath);
    }

    public function test_addWorktree_creates_a_checked_out_worktree(): void
    {
        $worktreePath = $this->tempDir . '/wt-456';

        $this->assertFalse($this->service->worktreeExists($this->gitRepoPath, $worktreePath));

        $result = $this->service->addWorktree($this->gitRepoPath, $worktreePath, 'feature/TICKET-456');

        $this->assertTrue($result);
        $this->assertDirectoryExists($worktreePath);
        $this->assertFileExists($worktreePath . '/README.md');
        $this->assertTrue($this->service->worktreeExists($this->gitRepoPath, $worktreePath));
    }

    public function test_addWorktree_creates_intermediate_directories(): void
    {
        $worktreePath = $this->tempDir . '/.ngramx/worktrees/ticket-456-repo';

        $result = $this->service->addWorktree($this->gitRepoPath, $worktreePath, 'feature/TICKET-456');

        $this->assertTrue($result);
        $this->assertDirectoryExists($worktreePath);
    }

    public function test_removeWorktree_removes_an_existing_worktree(): void
    {
        $worktreePath = $this->tempDir . '/wt-remove';
        $this->service->addWorktree($this->gitRepoPath, $worktreePath, 'feature/TICKET-456');

        // Simulate the untracked files a real worktree env leaves behind.
        file_put_contents($worktreePath . '/.env', "APP_URL=http://example.test\n");

        $result = $this->service->removeWorktree($this->gitRepoPath, $worktreePath);

        $this->assertTrue($result);
        $this->assertDirectoryDoesNotExist($worktreePath);
        $this->assertFalse($this->service->worktreeExists($this->gitRepoPath, $worktreePath));
    }

    public function test_removeWorktree_returns_false_for_unknown_path(): void
    {
        $result = $this->service->removeWorktree($this->gitRepoPath, $this->tempDir . '/does-not-exist');

        $this->assertFalse($result);
    }

    public function test_addWorktree_succeeds_despite_failing_post_checkout_hook(): void
    {
        // Simulate a CaptainHook-style post-checkout hook that depends on vendor/,
        // which is never primed in a brand-new worktree — the hook exits 127 and
        // git propagates that as the exit code of `git worktree add`.
        $this->installFailingPostCheckoutHook();

        $worktreePath = $this->tempDir . '/wt-hook';

        $result = $this->service->addWorktree($this->gitRepoPath, $worktreePath, 'feature/TICKET-456');

        $this->assertTrue($result, 'A failing post-checkout hook must not be reported as a failed worktree creation');
        $this->assertDirectoryExists($worktreePath);
        $this->assertFileExists($worktreePath . '/README.md');
        $this->assertTrue($this->service->worktreeExists($this->gitRepoPath, $worktreePath));
    }

    public function test_addWorktree_returns_false_for_unknown_branch(): void
    {
        $worktreePath = $this->tempDir . '/wt-missing-branch';

        $result = $this->service->addWorktree($this->gitRepoPath, $worktreePath, 'does-not-exist');

        $this->assertFalse($result);
        $this->assertFalse($this->service->worktreeExists($this->gitRepoPath, $worktreePath));
    }

    public function test_addWorktree_cleans_up_after_genuine_failure_so_retry_succeeds(): void
    {
        $worktreePath = $this->tempDir . '/wt-retry';

        $this->assertFalse($this->service->addWorktree($this->gitRepoPath, $worktreePath, 'does-not-exist'));
        $this->assertDirectoryDoesNotExist($worktreePath);

        // A retry with a valid branch must not trip over leftovers of the failure.
        $this->assertTrue($this->service->addWorktree($this->gitRepoPath, $worktreePath, 'feature/TICKET-456'));
        $this->assertTrue($this->service->worktreeExists($this->gitRepoPath, $worktreePath));
    }

    public function test_addWorktreeWithNewBranch_creates_branch_and_worktree(): void
    {
        $worktreePath = $this->tempDir . '/wt-new-branch';

        $this->assertFalse($this->service->localBranchExists($this->gitRepoPath, 'gig-2345'));

        $result = $this->service->addWorktreeWithNewBranch($this->gitRepoPath, $worktreePath, 'gig-2345');

        $this->assertTrue($result);
        $this->assertDirectoryExists($worktreePath);
        $this->assertTrue($this->service->worktreeExists($this->gitRepoPath, $worktreePath));
        $this->assertTrue($this->service->localBranchExists($this->gitRepoPath, 'gig-2345'));
    }

    public function test_addWorktreeWithNewBranch_succeeds_despite_failing_post_checkout_hook(): void
    {
        $this->installFailingPostCheckoutHook();

        $worktreePath = $this->tempDir . '/wt-new-branch-hook';

        $result = $this->service->addWorktreeWithNewBranch($this->gitRepoPath, $worktreePath, 'gig-9999');

        $this->assertTrue($result, 'A failing post-checkout hook must not be reported as a failed worktree creation');
        $this->assertTrue($this->service->worktreeExists($this->gitRepoPath, $worktreePath));
    }

    public function test_addWorktreeWithNewBranch_fails_when_branch_already_exists(): void
    {
        $worktreePath = $this->tempDir . '/wt-existing-branch';

        $result = $this->service->addWorktreeWithNewBranch($this->gitRepoPath, $worktreePath, 'feature/TICKET-456');

        $this->assertFalse($result);
        $this->assertFalse($this->service->worktreeExists($this->gitRepoPath, $worktreePath));
        $this->assertDirectoryDoesNotExist($worktreePath);
    }

    public function test_localBranchExists_distinguishes_local_from_remote_only_branches(): void
    {
        $this->assertTrue($this->service->localBranchExists($this->gitRepoPath, 'feature/TICKET-456'));

        // Remove the local branch so only origin/<branch> remains.
        $this->runGitCommand('git branch -D feature/TICKET-456');

        $this->assertFalse($this->service->localBranchExists($this->gitRepoPath, 'feature/TICKET-456'));
    }

    public function test_getCurrentBranch_returns_checked_out_branch(): void
    {
        $this->runGitCommand('git checkout feature/TICKET-456');

        $this->assertSame('feature/TICKET-456', $this->service->getCurrentBranch($this->gitRepoPath));
    }

    public function test_isIntegrationBranch_recognises_mainline_branches(): void
    {
        $this->assertTrue($this->service->isIntegrationBranch('main'));
        $this->assertTrue($this->service->isIntegrationBranch('staging'));
        $this->assertTrue($this->service->isIntegrationBranch('production'));
        $this->assertFalse($this->service->isIntegrationBranch('gig-123-fix-thing'));
    }

    public function test_resolveDefaultIntegrationBranch_prefers_origin_head(): void
    {
        $this->assertSame('main', $this->service->resolveDefaultIntegrationBranch($this->gitRepoPath));
    }

    public function test_hasUncommittedChanges_detects_dirty_working_tree(): void
    {
        $this->assertFalse($this->service->hasUncommittedChanges($this->gitRepoPath));

        file_put_contents($this->gitRepoPath . '/dirty.txt', 'pending');

        $this->assertTrue($this->service->hasUncommittedChanges($this->gitRepoPath));
    }

    public function test_stashPush_and_stashPop_round_trip(): void
    {
        $this->runGitCommand('git checkout feature/TICKET-456');
        file_put_contents($this->gitRepoPath . '/dirty.txt', 'pending');

        $this->assertTrue($this->service->stashPush($this->gitRepoPath, 'test stash'));
        $this->assertFalse($this->service->hasUncommittedChanges($this->gitRepoPath));

        $this->assertTrue($this->service->stashPop($this->gitRepoPath));
        $this->assertTrue($this->service->hasUncommittedChanges($this->gitRepoPath));
        $this->assertFileExists($this->gitRepoPath . '/dirty.txt');
    }

    public function test_checkoutLocalBranch_checks_out_without_merging(): void
    {
        $this->runGitCommand('git checkout feature/TICKET-456');

        $this->assertTrue($this->service->checkoutLocalBranch($this->gitRepoPath, 'main'));
        $this->assertSame('main', $this->service->getCurrentBranch($this->gitRepoPath));
    }

    /**
     * Install a post-checkout hook that always fails, mimicking a hook runner
     * invoked via a relative vendor/ path that is absent in a fresh worktree.
     */
    private function installFailingPostCheckoutHook(): void
    {
        $hookPath = $this->gitRepoPath . '/.git/hooks/post-checkout';
        file_put_contents($hookPath, "#!/bin/sh\nvendor/bin/captainhook post-checkout\n");
        chmod($hookPath, 0755);
    }

    /**
     * Recursively remove a directory
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $scandirResult = scandir($dir);
        if ($scandirResult === false) {
            return;
        }

        $files = array_diff($scandirResult, ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
