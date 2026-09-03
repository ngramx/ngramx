<?php

declare(strict_types=1);

namespace Ngramx\Docker;

/**
 * Why a staged Docker Desktop bind mount is considered dead.
 */
enum StaleBindMountReason
{
    /**
     * The staged mount still points at an inode that has been unlinked on the
     * host — `/proc/self/mountinfo` reports the mount root as `…//deleted`.
     * Typically a file that git or an editor replaced.
     */
    case DeletedInode;

    /**
     * The host path the entry stages no longer exists at all. Typically a
     * removed worktree, whose staged mounts outlive the directory.
     */
    case MissingHostPath;

    /**
     * The container engine itself reported the staged path as missing when it
     * tried to mount it. We trust that over our own heuristics: the entry is
     * unusable whatever the distro's mount table says about it.
     */
    case EngineReportedMissing;

    public function describe(): string
    {
        return match ($this) {
            self::DeletedInode => 'pinned to a deleted inode — the file was replaced on the host',
            self::MissingHostPath => 'host path no longer exists',
            self::EngineReportedMissing => 'Docker could not resolve the staged mount',
        };
    }
}
