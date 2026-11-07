<?php

declare(strict_types=1);

namespace Ngramx\Worktree;

use Ngramx\Git\GitExcludeManager;
use Ngramx\Git\GitRepositoryService;
use Symfony\Component\Process\Process;

/**
 * Implements Claude Code WorktreeCreate / WorktreeRemove hook behaviour.
 *
 * Creates under {@see WorktreePaths::NGRAMX_WORKTREE_DIR} and never runs
 * `ngramx up`. Intended to be invoked as `ngramx claude-worktree create|remove`
 * from ~/.claude/settings.json (or a project settings file).
 */
class ClaudeWorktreeHookHandler
{
    public function __construct(
        private readonly GitRepositoryService $gitRepositoryService = new GitRepositoryService(),
        private readonly GitExcludeManager $gitExcludeManager = new GitExcludeManager(),
    ) {
    }

    /**
     * @return array{ok: true, path: string}|array{ok: false, error: string}
     */
    public function create(string $hookJson): array
    {
        $payload = $this->decode($hookJson);
        if ($payload === null) {
            return ['ok' => false, 'error' => 'Invalid JSON hook input'];
        }

        $name = isset($payload['name']) && is_string($payload['name']) ? trim($payload['name']) : '';
        if ($name === '') {
            return ['ok' => false, 'error' => 'hook input missing .name'];
        }

        if (isset($payload['cwd']) && is_string($payload['cwd']) && $payload['cwd'] !== '') {
            $cwd = $payload['cwd'];
        } else {
            $cwd = getcwd();
            if ($cwd === false) {
                return ['ok' => false, 'error' => 'Could not determine working directory'];
            }
        }

        $repositoryPath = $this->resolveRepositoryRoot($cwd);
        if ($repositoryPath === null) {
            return ['ok' => false, 'error' => "Not a git repository: {$cwd}"];
        }

        $sanitizedName = WorktreeIdentity::sanitizeSegment($name);
        if ($sanitizedName === '') {
            return ['ok' => false, 'error' => 'hook input .name sanitised to empty string'];
        }

        $repoName = WorktreeIdentity::sanitizeSegment(basename($repositoryPath));
        $ticketSlug = WorktreeIdentity::deriveTicketSlug($sanitizedName, $sanitizedName);

        if (preg_match('/^[a-z]+-\d+$/', $ticketSlug) === 1) {
            $folderName = WorktreeIdentity::folderName($ticketSlug, $repoName);
            $branch = $ticketSlug;
        } else {
            $folderName = $sanitizedName;
            $branch = 'worktree-' . $sanitizedName;
        }

        $worktreePath = $repositoryPath . '/' . WorktreePaths::NGRAMX_WORKTREE_DIR . '/' . $folderName;

        if (is_dir($worktreePath)) {
            return ['ok' => true, 'path' => $worktreePath];
        }

        $created = $this->gitRepositoryService->localBranchExists($repositoryPath, $branch)
            ? $this->gitRepositoryService->addWorktree($repositoryPath, $worktreePath, $branch)
            : $this->gitRepositoryService->addWorktreeWithNewBranch($repositoryPath, $worktreePath, $branch);

        if (!$created) {
            $detail = $this->gitRepositoryService->lastCheckoutError();
            $suffix = $detail !== '' ? ": {$detail}" : '';

            return ['ok' => false, 'error' => "Failed to create worktree at {$worktreePath}{$suffix}"];
        }

        $this->gitExcludeManager->ensureExcluded($repositoryPath, '/.ngramx/worktrees/');

        return ['ok' => true, 'path' => $worktreePath];
    }

    /**
     * @return array{ok: true, path: string}|array{ok: false, error: string}
     */
    public function remove(string $hookJson): array
    {
        $payload = $this->decode($hookJson);
        if ($payload === null) {
            return ['ok' => false, 'error' => 'Invalid JSON hook input'];
        }

        $worktreePath = isset($payload['worktree_path']) && is_string($payload['worktree_path'])
            ? trim($payload['worktree_path'])
            : '';

        if ($worktreePath === '') {
            return ['ok' => false, 'error' => 'hook input missing .worktree_path'];
        }

        if (!WorktreePaths::isNgramxManaged($worktreePath)) {
            return ['ok' => false, 'error' => "worktree '{$worktreePath}' is not under .ngramx/worktrees/"];
        }

        $repositoryPath = $this->repositoryRootFromManagedPath($worktreePath);
        if ($repositoryPath === null) {
            return ['ok' => false, 'error' => "could not resolve repository root from '{$worktreePath}'"];
        }

        if (!is_dir($worktreePath)) {
            $this->gitRepositoryService->pruneWorktrees($repositoryPath);

            return ['ok' => true, 'path' => $worktreePath];
        }

        if (!$this->gitRepositoryService->removeWorktree($repositoryPath, $worktreePath)) {
            // Match the shell hook: force-remove the directory if git refuses.
            $this->deleteDirectory($worktreePath);
            $this->gitRepositoryService->pruneWorktrees($repositoryPath);
        }

        return ['ok' => true, 'path' => $worktreePath];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $hookJson): ?array
    {
        $decoded = json_decode($hookJson, true);

        return is_array($decoded) ? $decoded : null;
    }

    protected function resolveRepositoryRoot(string $cwd): ?string
    {
        $process = new Process(['git', 'rev-parse', '--show-toplevel'], $cwd);
        $process->setTimeout(10);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $root = trim($process->getOutput());

        return $root !== '' ? $root : null;
    }

    private function repositoryRootFromManagedPath(string $worktreePath): ?string
    {
        $normalized = str_replace('\\', '/', rtrim($worktreePath, '/\\'));
        $marker = '/' . WorktreePaths::NGRAMX_WORKTREE_DIR . '/';
        $pos = strpos($normalized, $marker);
        if ($pos === false) {
            return null;
        }

        $root = substr($normalized, 0, $pos);
        if ($root === '' || (!is_dir($root . '/.git') && !is_file($root . '/.git'))) {
            return null;
        }

        return $root;
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        @rmdir($path);
    }
}
