<?php

declare(strict_types=1);

namespace Ngramx\Worktree;

/**
 * One environment in a repository's picture of itself: the main checkout, or a
 * worktree under `.ngramx/worktrees/`.
 */
readonly class EnvironmentSnapshot
{
    /**
     * @param string $name Folder name for a worktree; the repo folder for the root.
     * @param string $path Absolute path to the checkout.
     * @param ?string $branch Checked-out branch, or null when it can't be read.
     * @param bool $running Whether the primary service is up for this environment.
     * @param ?string $url URL the environment is reachable on; null when stopped
     *        or never started.
     * @param ?string $namespace Docker Compose project name, when known.
     * @param bool $isCurrent Whether the command was run from inside this checkout.
     */
    public function __construct(
        public string $name,
        public string $path,
        public ?string $branch,
        public bool $running,
        public ?string $url,
        public ?string $namespace,
        public bool $isCurrent = false,
    ) {
    }
}
