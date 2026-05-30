<?php

declare(strict_types=1);

namespace Cortex\Git;

use RuntimeException;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Process\Process;

class GitRepositoryService
{
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
     * so a re-run of `cortex review` picks up new commits pushed since the last checkout. If the
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

        return $checkoutProcess->isSuccessful();
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
     */
    public function addWorktree(string $repositoryPath, string $worktreePath, string $branch): bool
    {
        $parent = dirname($worktreePath);
        if (!is_dir($parent)) {
            @mkdir($parent, 0755, true);
        }

        $addProcess = new Process(
            ['git', 'worktree', 'add', $worktreePath, $branch],
            $repositoryPath
        );
        $addProcess->setTimeout(120);
        $addProcess->run();

        if (!$addProcess->isSuccessful()) {
            return false;
        }

        // Best-effort: bring the worktree up to date with origin. A diverged or
        // already-current branch makes this a no-op/failure we can safely ignore.
        $ffProcess = new Process(
            ['git', '-C', $worktreePath, 'merge', '--ff-only', 'origin/' . $branch],
            $repositoryPath
        );
        $ffProcess->setTimeout(60);
        $ffProcess->run();

        return true;
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
