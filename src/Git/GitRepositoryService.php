<?php

declare(strict_types=1);

namespace Ngramx\Git;

use RuntimeException;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Process\Process;

class GitRepositoryService
{
    /**
     * Branch names that represent the main integration line — not feature work.
     * Used by `ngramx worktree` when no ticket is given to decide whether the
     * current checkout can be moved into a worktree.
     *
     * @var list<string>
     */
    private const INTEGRATION_BRANCHES = [
        'main',
        'master',
        'staging',
        'stage',
        'production',
        'prod',
    ];

    /**
     * The combined git stderr/stdout from the most recent failed checkout, kept so
     * callers can surface the underlying reason (e.g. a dirty working tree) instead
     * of a generic failure message. Empty when the last checkout succeeded.
     */
    private string $lastCheckoutError = '';

    /**
     * Fetch from origin, pruning remote-tracking refs for branches that no longer exist
     * on the remote so the candidate branch list stays in sync with origin.
     */
    public function fetchFromOrigin(string $repositoryPath): bool
    {
        $fetchProcess = new Process(['git', 'fetch', '--prune', 'origin'], $repositoryPath);
        $fetchProcess->setTimeout(60);
        $fetchProcess->run();

        return $fetchProcess->isSuccessful();
    }

    /**
     * Return the currently checked-out branch name, or null when HEAD is detached.
     */
    public function getCurrentBranch(string $repositoryPath): ?string
    {
        $process = new Process(['git', 'branch', '--show-current'], $repositoryPath);
        $process->setTimeout(10);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $branch = trim($process->getOutput());

        return $branch !== '' ? $branch : null;
    }

    /**
     * Whether the branch is an integration line (main/staging/production, etc.)
     * rather than a feature branch.
     */
    public function isIntegrationBranch(string $branch): bool
    {
        return in_array(strtolower($branch), self::INTEGRATION_BRANCHES, true);
    }

    /**
     * Resolve the repository's default integration branch — the branch the main
     * checkout should return to after moving feature work into a worktree.
     */
    public function resolveDefaultIntegrationBranch(string $repositoryPath): string
    {
        $headProcess = new Process(['git', 'symbolic-ref', '--short', 'refs/remotes/origin/HEAD'], $repositoryPath);
        $headProcess->setTimeout(10);
        $headProcess->run();

        if ($headProcess->isSuccessful()) {
            $ref = trim($headProcess->getOutput());
            if (str_starts_with($ref, 'origin/')) {
                return substr($ref, strlen('origin/'));
            }
        }

        foreach (['main', 'master'] as $candidate) {
            if ($this->localBranchExists($repositoryPath, $candidate)) {
                return $candidate;
            }
        }

        return 'main';
    }

    /**
     * Whether the working tree has staged or unstaged changes (including untracked
     * files when $includeUntracked is true).
     */
    public function hasUncommittedChanges(string $repositoryPath, bool $includeUntracked = true): bool
    {
        $args = ['git', 'status', '--porcelain'];
        if (!$includeUntracked) {
            $args[] = '--untracked-files=no';
        }

        $process = new Process($args, $repositoryPath);
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful()) {
            return false;
        }

        return trim($process->getOutput()) !== '';
    }

    /**
     * Stash uncommitted changes when the working tree is dirty. Returns true when
     * there was nothing to stash or the stash succeeded.
     */
    public function stashPush(string $repositoryPath, ?string $message = null): bool
    {
        if (!$this->hasUncommittedChanges($repositoryPath)) {
            return true;
        }

        $args = ['git', 'stash', 'push', '-u'];
        if ($message !== null && $message !== '') {
            $args[] = '-m';
            $args[] = $message;
        }

        $process = new Process($args, $repositoryPath);
        $process->setTimeout(60);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Pop the most recent stash entry in the given checkout/worktree.
     */
    public function stashPop(string $repositoryPath): bool
    {
        $process = new Process(['git', 'stash', 'pop'], $repositoryPath);
        $process->setTimeout(60);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Check out an existing local branch without merging from origin.
     */
    public function checkoutLocalBranch(string $repositoryPath, string $branch): bool
    {
        $process = new Process(['git', 'checkout', $branch], $repositoryPath);
        $process->setTimeout(60);
        $process->run();

        if ($process->isSuccessful()) {
            $this->lastCheckoutError = '';

            return true;
        }

        $this->lastCheckoutError = trim(
            $process->getErrorOutput() . "\n" . $process->getOutput()
        );

        return false;
    }

    /**
     * Find branches containing a search string
     *
     * @return array<string> Array of branch names (without origin/ prefix)
     */
    public function findBranchesContaining(string $repositoryPath, string $searchString): array
    {
        $escapedSearch = escapeshellarg($searchString);

        $branchProcess = Process::fromShellCommandline("git branch -r | grep $escapedSearch", $repositoryPath);
        $branchProcess->setTimeout(30);
        $branchProcess->run();

        if (!$branchProcess->isSuccessful()) {
            return [];
        }

        $branchOutput = trim($branchProcess->getOutput());
        if (empty($branchOutput)) {
            return [];
        }

        $branchLines = explode("\n", $branchOutput);
        $branches = array_filter(
            array_map('trim', $branchLines),
            fn ($branch): bool => is_string($branch) && $branch !== '' && !str_contains($branch, 'HEAD ->')
        );

        /** @var array<string> $branchNames */
        $branchNames = [];
        foreach ($branches as $branch) {
            $branchName = preg_replace('/^origin\//', '', $branch) ?? $branch;
            if (str_contains($branchName, '->')) {
                $parts = explode('->', $branchName);
                $branchName = trim(end($parts));
                $branchName = preg_replace('/^origin\//', '', $branchName) ?? $branchName;
            }
            if ($branchName !== '') {
                $branchNames[] = $branchName;
            }
        }

        return array_values(array_unique($branchNames));
    }

    /**
     * Checkout the specified branch, fast-forwarding from origin if it already exists locally.
     *
     * If the local branch exists, it is checked out and fast-forward merged from origin/<branch>
     * so a re-run of `ngramx review` picks up new commits pushed since the last checkout. If the
     * local branch has diverged (local-only commits), the fast-forward fails and this returns
     * false so the caller can surface an error rather than silently serve stale code.
     */
    public function checkoutBranch(string $repositoryPath, string $branch): bool
    {
        $escapedBranch = escapeshellarg($branch);

        $existsProcess = Process::fromShellCommandline(
            "git show-ref --verify --quiet refs/heads/$escapedBranch",
            $repositoryPath
        );
        $existsProcess->setTimeout(10);
        $existsProcess->run();

        if ($existsProcess->isSuccessful()) {
            $command = "git checkout $escapedBranch && git merge --ff-only origin/$escapedBranch";
        } else {
            $command = "git checkout -b $escapedBranch origin/$escapedBranch";
        }

        $checkoutProcess = Process::fromShellCommandline($command, $repositoryPath);
        $checkoutProcess->setTimeout(60);
        $checkoutProcess->run();

        if ($checkoutProcess->isSuccessful()) {
            $this->lastCheckoutError = '';

            return true;
        }

        $this->lastCheckoutError = trim(
            $checkoutProcess->getErrorOutput() . "\n" . $checkoutProcess->getOutput()
        );

        return false;
    }

    /**
     * The git output explaining why the most recent checkoutBranch() call failed,
     * or an empty string if the last checkout succeeded or never ran.
     */
    public function lastCheckoutError(): string
    {
        return $this->lastCheckoutError;
    }

    /**
     * Check whether a git worktree already exists at the given path.
     */
    public function worktreeExists(string $repositoryPath, string $worktreePath): bool
    {
        $process = new Process(['git', 'worktree', 'list', '--porcelain'], $repositoryPath);
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful()) {
            return false;
        }

        $target = $this->normalizePath($worktreePath);

        foreach (explode("\n", $process->getOutput()) as $line) {
            if (str_starts_with($line, 'worktree ')) {
                $existing = $this->normalizePath(trim(substr($line, strlen('worktree '))));
                if ($existing === $target) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Create a git worktree at $worktreePath checked out to $branch.
     *
     * Uses git's DWIM behaviour so a branch that only exists on origin is created
     * as a local tracking branch. After creation we best-effort fast-forward to
     * origin so a re-used branch picks up newly pushed commits.
     *
     * Git hooks are disabled for the creation checkout: `git worktree add` fires
     * the repo's post-checkout hook, and in a brand-new worktree the hook's
     * dependencies (e.g. vendor/bin/captainhook) are not primed yet, so the hook
     * fails and git propagates its exit code — falsely reporting a perfectly
     * good worktree as a failed creation. Hooks resume normally for real
     * operations inside the worktree afterwards.
     */
    public function addWorktree(string $repositoryPath, string $worktreePath, string $branch): bool
    {
        $parent = dirname($worktreePath);
        if (!is_dir($parent)) {
            @mkdir($parent, 0755, true);
        }

        $addProcess = new Process(
            ['git', '-c', 'core.hooksPath=/dev/null', 'worktree', 'add', $worktreePath, $branch],
            $repositoryPath
        );
        $addProcess->setTimeout(120);
        $addProcess->run();

        // The exit code alone is not trustworthy: any failing hook (not just
        // CaptainHook) makes `git worktree add` exit non-zero after the worktree
        // was created correctly. Registration in `git worktree list` is the
        // authoritative success signal.
        if (!$addProcess->isSuccessful() && !$this->worktreeExists($repositoryPath, $worktreePath)) {
            // A genuine failure can leave a half-created directory or a stale
            // admin entry behind; clean both up so a retry starts clean instead
            // of tripping over the leftover registration.
            $this->cleanUpFailedWorktree($repositoryPath, $worktreePath);

            return false;
        }

        // Best-effort: bring the worktree up to date with origin. A diverged or
        // already-current branch makes this a no-op/failure we can safely ignore.
        // Hooks stay disabled here for the same reason as above: dependencies are
        // primed only after the worktree exists.
        $ffProcess = new Process(
            ['git', '-c', 'core.hooksPath=/dev/null', '-C', $worktreePath, 'merge', '--ff-only', 'origin/' . $branch],
            $repositoryPath
        );
        $ffProcess->setTimeout(60);
        $ffProcess->run();

        return true;
    }

    /**
     * Check whether a branch exists locally (refs/heads).
     */
    public function localBranchExists(string $repositoryPath, string $branch): bool
    {
        $process = new Process(
            ['git', 'rev-parse', '--verify', '--quiet', 'refs/heads/' . $branch],
            $repositoryPath
        );
        $process->setTimeout(30);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Create a git worktree at $worktreePath on a brand-new branch $newBranch.
     *
     * The branch is created from the repository's current HEAD. Hooks are
     * disabled for the creation checkout for the same reason as addWorktree():
     * the new worktree has no primed dependencies yet, so a failing hook would
     * falsely report the creation as failed.
     */
    public function addWorktreeWithNewBranch(string $repositoryPath, string $worktreePath, string $newBranch): bool
    {
        $parent = dirname($worktreePath);
        if (!is_dir($parent)) {
            @mkdir($parent, 0755, true);
        }

        $addProcess = new Process(
            ['git', '-c', 'core.hooksPath=/dev/null', 'worktree', 'add', '-b', $newBranch, $worktreePath],
            $repositoryPath
        );
        $addProcess->setTimeout(120);
        $addProcess->run();

        if (!$addProcess->isSuccessful() && !$this->worktreeExists($repositoryPath, $worktreePath)) {
            $this->cleanUpFailedWorktree($repositoryPath, $worktreePath);

            return false;
        }

        return true;
    }

    /**
     * Remove the remnants of a failed `git worktree add`: the partially created
     * directory (if any) and the pruneable administrative entry, so a retry does
     * not fail with "already registered" against a worktree that never existed.
     */
    private function cleanUpFailedWorktree(string $repositoryPath, string $worktreePath): void
    {
        if (is_dir($worktreePath)) {
            $removeProcess = new Process(['rm', '-rf', $worktreePath]);
            $removeProcess->setTimeout(60);
            $removeProcess->run();
        }

        $this->pruneWorktrees($repositoryPath);
    }

    /**
     * Remove a git worktree and prune its administrative entry.
     *
     * Uses --force because the worktree will usually contain untracked files
     * (a copied .env, a generated docker-compose.override.yml) that would
     * otherwise make git refuse to remove it.
     */
    public function removeWorktree(string $repositoryPath, string $worktreePath): bool
    {
        $removeProcess = new Process(
            ['git', 'worktree', 'remove', '--force', $worktreePath],
            $repositoryPath
        );
        $removeProcess->setTimeout(60);
        $removeProcess->run();

        if (!$removeProcess->isSuccessful()) {
            return false;
        }

        $this->pruneWorktrees($repositoryPath);

        return true;
    }

    /**
     * Prune administrative entries for worktrees whose directories have been removed.
     */
    public function pruneWorktrees(string $repositoryPath): void
    {
        $pruneProcess = new Process(['git', 'worktree', 'prune'], $repositoryPath);
        $pruneProcess->setTimeout(30);
        $pruneProcess->run();
    }

    /**
     * Normalise a filesystem path for comparison, resolving it via realpath when
     * it exists and otherwise collapsing trailing slashes.
     */
    private function normalizePath(string $path): string
    {
        $resolved = realpath($path);

        return $resolved !== false ? $resolved : rtrim($path, '/');
    }

    /**
     * Find the most recent branch by checking commit dates
     *
     * @param array<string> $branches
     * @throws RuntimeException If no branches provided
     */
    public function findMostRecentBranch(string $repositoryPath, array $branches): string
    {
        if (empty($branches)) {
            throw new RuntimeException('No branches found');
        }

        $mostRecentBranch = null;
        $mostRecentDate = 0;

        foreach ($branches as $branch) {
            $dateProcess = new Process(['git', 'log', '-1', '--format=%ct', "origin/$branch"], $repositoryPath);
            $dateProcess->setTimeout(30);
            $dateProcess->run();

            if ($dateProcess->isSuccessful()) {
                $timestamp = (int) trim($dateProcess->getOutput());
                if ($timestamp > $mostRecentDate) {
                    $mostRecentDate = $timestamp;
                    $mostRecentBranch = $branch;
                }
            }
        }

        if ($mostRecentBranch === null) {
            return $branches[0];
        }

        return $mostRecentBranch;
    }

    /**
     * Select a branch from the list, defaulting to the most recent one
     *
     * @param array<string> $branches
     * @param callable(string): bool $preferenceCallback Optional callback to prefer certain branches (returns true if branch should be preferred)
     */
    public function selectBranch(
        string $repositoryPath,
        array $branches,
        InputInterface $input,
        OutputInterface $output,
        callable $infoCallback,
        callable $warningCallback,
        ?callable $preferenceCallback = null
    ): string {
        if (count($branches) === 1) {
            $infoCallback('Found single branch: ' . $branches[0]);
            return $branches[0];
        }

        // If multiple branches, find the most recent one
        try {
            $defaultBranch = $this->findMostRecentBranch($repositoryPath, $branches);
        } catch (RuntimeException $e) {
            $defaultBranch = $branches[0];
        }

        // Apply preference callback if provided
        if ($preferenceCallback !== null && !$preferenceCallback($defaultBranch)) {
            foreach ($branches as $branch) {
                if ($preferenceCallback($branch)) {
                    $defaultBranch = $branch;
                    break;
                }
            }
        }

        $infoCallback('Found ' . count($branches) . ' branches:');
        foreach ($branches as $branch) {
            $marker = ($branch === $defaultBranch) ? ' (most recent)' : '';
            $output->writeln('  <fg=#D2DCE5>- ' . $branch . $marker . '</>');
        }

        $question = new ChoiceQuestion(
            'Select a branch to checkout:',
            $branches,
            $defaultBranch
        );
        $question->setNormalizer(fn ($value) => $value);

        $helper = new QuestionHelper();
        $selected = $helper->ask($input, $output, $question);

        return $selected ?? $defaultBranch;
    }
}
