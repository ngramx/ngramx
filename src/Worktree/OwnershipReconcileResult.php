<?php

declare(strict_types=1);

namespace Ngramx\Worktree;

/**
 * Outcome of an attempt to reconcile a worktree's file ownership back to the
 * developer's uid/gid (see {@see WorktreeOwnershipReconciler}). Carries enough
 * detail for callers to print an accurate message — including the manual chown
 * command to run when the automated fix could not be applied.
 */
final class OwnershipReconcileResult
{
    public const RECONCILED = 'reconciled';
    public const SKIPPED = 'skipped';
    public const FAILED = 'failed';

    private function __construct(
        public readonly string $status,
        public readonly ?int $uid,
        public readonly ?int $gid,
        public readonly ?string $reason,
    ) {
    }

    public static function reconciled(int $uid, int $gid): self
    {
        return new self(self::RECONCILED, $uid, $gid, null);
    }

    public static function skipped(string $reason): self
    {
        return new self(self::SKIPPED, null, null, $reason);
    }

    public static function failed(int $uid, int $gid): self
    {
        return new self(self::FAILED, $uid, $gid, null);
    }

    public function isReconciled(): bool
    {
        return $this->status === self::RECONCILED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::FAILED;
    }

    public function isSkipped(): bool
    {
        return $this->status === self::SKIPPED;
    }
}
