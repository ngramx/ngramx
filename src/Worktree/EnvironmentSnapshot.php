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
     * @param ?int $portOffset Offset applied to this environment's host ports,
     *        from the lock file. Null when never started; 0 when started with
     *        no offset, and also when started with `--no-host-mapping`, where
     *        no host ports exist to offset in the first place.
     * @param ?AgentRun $agent Coding-agent run recorded against this
     *        environment, or null when no runner has claimed it.
     */
    public function __construct(
        public string $name,
        public string $path,
        public ?string $branch,
        public bool $running,
        public ?string $url,
        public ?string $namespace,
        public bool $isCurrent = false,
        public ?int $portOffset = null,
        public ?AgentRun $agent = null,
    ) {
    }
}
