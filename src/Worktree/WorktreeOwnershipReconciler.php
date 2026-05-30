<?php

declare(strict_types=1);

namespace Ngramx\Worktree;

use Symfony\Component\Process\Process;

/**
 * Hands a bind-mounted worktree's files back to the developer's uid/gid so the
 * container's non-root runtime user (e.g. `www-data`) can write `storage/` and
 * `bootstrap/cache/`.
 *
 * Why this is needed
 * ------------------
 * A fresh worktree starts empty, so the first processes to write runtime files
 * are whatever runs as root inside the container: the project entrypoint
 * (`composer install`, `php artisan migrate`, ...) and Ngramx's own
 * `docker compose exec` steps. Those land root-owned, and the container's
 * non-root runtime user can no longer write them. The most visible symptoms are
 * a 500 from Laravel ("storage/logs/laravel.log could not be opened in append
 * mode") and the `tempnam(): file created in the system's temporary directory`
 * notice from `Filesystem::replace()` when `bootstrap/cache` or
 * `storage/framework` is not writable and PHP silently falls back to /tmp.
 *
 * Why not `getmyuid()`
 * --------------------
 * The target must be the human developer's uid — the invariant a healthy parent
 * checkout already has, and the uid dev images conventionally map their runtime
 * user to (e.g. `usermod -u 1000 www-data`). Using the uid of the *Ngramx
 * process* breaks the moment Ngramx is run as root (sudo, a root shell, CI):
 * it would `chown -R 0:0` the whole worktree, which is exactly the breakage this
 * is meant to repair. Instead we read the owner of the directory that *contains*
 * the worktree (`.ngramx/worktrees/`), which is part of the developer's checkout
 * and is never touched by this chown, so it reflects the developer's uid no
 * matter who runs Ngramx. As a safety net we refuse to chown to root.
 *
 * Giving away ownership of root-owned files requires root, so the chown runs in
 * a short-lived root helper container with the worktree bind-mounted; the host
 * user running Ngramx cannot chown files it does not own.
 */
class WorktreeOwnershipReconciler
{
    private readonly WorktreeGitMount $gitMount;

    public function __construct(?WorktreeGitMount $gitMount = null)
    {
        $this->gitMount = $gitMount ?? new WorktreeGitMount();
    }

    /**
     * Reconcile the worktree's file ownership to the developer's uid/gid.
     */
    public function reconcile(string $worktreePath): OwnershipReconcileResult
    {
        // Only linked worktrees need this — a normal checkout's files were created
        // by the developer long ago and already carry the right ownership.
        if ($this->gitMount->resolve($worktreePath) === null) {
            return OwnershipReconcileResult::skipped('not a linked worktree');
        }

        $owner = $this->resolveTargetOwner($worktreePath);
        if ($owner === null) {
            return OwnershipReconcileResult::skipped('could not resolve a non-root developer uid');
        }

        [$uid, $gid] = $owner;

        $process = new Process([
            'docker', 'run', '--rm',
            '-v', $worktreePath . ':/worktree',
            'alpine', 'chown', '-R', $uid . ':' . $gid, '/worktree',
        ]);
        $process->setTimeout(120);
        $process->run();

        return $process->isSuccessful()
            ? OwnershipReconcileResult::reconciled($uid, $gid)
            : OwnershipReconcileResult::failed($uid, $gid);
    }

    /**
     * The uid/gid the worktree's files should belong to: the owner of the
     * directory that contains the worktree (`.ngramx/worktrees/`). That directory
     * is part of the developer's checkout and is never re-chowned here, so it
     * reliably reflects the human developer's uid even when Ngramx itself runs as
     * root.
     *
     * Returns null when the owner cannot be read, or when it resolves to root —
     * chowning a worktree to root is the very breakage this guards against, so we
     * refuse to "fix" it into that state.
     *
     * @return array{0: int, 1: int}|null
     */
    public function resolveTargetOwner(string $worktreePath): ?array
    {
        $reference = dirname(rtrim($worktreePath, '/'));

        $uid = @fileowner($reference);
        $gid = @filegroup($reference);

        if ($uid === false || $gid === false) {
            return null;
        }

        if ($uid === 0) {
            return null;
        }

        return [$uid, $gid];
    }
}
