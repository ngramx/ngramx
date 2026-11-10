<?php

declare(strict_types=1);

namespace Ngramx\Worktree;

use Ngramx\Git\GitRepositoryService;
use RuntimeException;

/**
 * Move a Claude Code worktree from {@see WorktreePaths::CLAUDE_WORKTREE_DIR}
 * into {@see WorktreePaths::NGRAMX_WORKTREE_DIR} so ngramx can manage it.
 */
final class WorktreeAdopter
{
    public function __construct(
        private readonly GitRepositoryService $gitRepositoryService = new GitRepositoryService(),
    ) {
    }

    /**
     * Adopt $claudeWorktreePath into `.ngramx/worktrees/<folder>` and return the new path.
     *
     * @param ?string $preferredFolder When set (e.g. the folder `ngramx worktree`
     *        would create for the ticket), use that name so adoption lands where
     *        the rest of the command expects to find the worktree.
     */
    public function adopt(
        string $repositoryPath,
        string $claudeWorktreePath,
        ?string $branch = null,
        ?string $preferredFolder = null,
    ): string {
        if (!WorktreePaths::isClaudeDefault($claudeWorktreePath)) {
            throw new RuntimeException("Worktree is not under .claude/worktrees/: {$claudeWorktreePath}");
        }

        if (!$this->gitRepositoryService->worktreeExists($repositoryPath, $claudeWorktreePath)) {
            throw new RuntimeException("Worktree is not registered with git: {$claudeWorktreePath}");
        }

        $targetFolder = $preferredFolder !== null && $preferredFolder !== ''
            ? WorktreeIdentity::sanitizeSegment($preferredFolder)
            : $this->deriveTargetFolder($repositoryPath, $claudeWorktreePath, $branch);
        $targetPath = $repositoryPath . '/' . WorktreePaths::NGRAMX_WORKTREE_DIR . '/' . $targetFolder;

        if (is_dir($targetPath)) {
            throw new RuntimeException("Target worktree already exists: {$targetPath}");
        }

        if (!$this->gitRepositoryService->moveWorktree($repositoryPath, $claudeWorktreePath, $targetPath)) {
            $error = $this->gitRepositoryService->lastMoveError();
            $message = "Failed to move worktree from {$claudeWorktreePath} to {$targetPath}";
            if ($error !== '') {
                $message .= ": {$error}";
            }
            throw new RuntimeException($message);
        }

        return $targetPath;
    }

    /**
     * Folder name under `.ngramx/worktrees/`, matching ngramx worktree creation.
     */
    public function deriveTargetFolder(string $repositoryPath, string $claudeWorktreePath, ?string $branch): string
    {
        $claudeFolder = basename($claudeWorktreePath);
        $repoName = WorktreeIdentity::sanitizeSegment(basename($repositoryPath));

        if ($branch !== null && $branch !== '' && $branch !== '(detached)') {
            $ticketSlug = WorktreeIdentity::deriveTicketSlug($branch, $claudeFolder);

            if (preg_match('/^[a-z]+-\d+$/', $ticketSlug) === 1) {
                return WorktreeIdentity::folderName($ticketSlug, $repoName);
            }
        }

        return WorktreeIdentity::folderName(
            WorktreeIdentity::sanitizeSegment($claudeFolder),
            $repoName
        );
    }
}
