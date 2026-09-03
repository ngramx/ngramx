<?php

declare(strict_types=1);

namespace Ngramx\Docker;

/**
 * A dangling entry in Docker Desktop's WSL bind-mount staging area.
 *
 * Docker Desktop cannot hand a WSL path to the Linux VM that runs the engine,
 * so it stages every bind mount inside the distro at
 * `/mnt/wsl/docker-desktop-bind-mounts/<distro>/<sha256 of the host path>` and
 * mounts *that* into the container as
 * `/run/desktop/mnt/host/wsl/docker-desktop-bind-mounts/<distro>/<hash>`.
 *
 * For a single-file mount the staged entry pins one inode. Rewrite the file on
 * the host — a `git checkout`, a branch switch, an editor that writes a new
 * file and renames it over the old one — and the inode is replaced. The staged
 * mount keeps the *old*, now-unlinked inode (`/proc/self/mountinfo` shows the
 * root as `…//deleted`), and the next container create fails with:
 *
 *     error mounting "/run/desktop/mnt/host/wsl/docker-desktop-bind-mounts/…"
 *     to rootfs at "/usr/local/etc/php/conf.d/local.ini": no such file or directory
 *
 * The same thing happens when a whole directory goes away — deleting an ngramx
 * worktree leaves staged mounts behind for every path under it, and because
 * the staging path is a hash of the host path, the *next* worktree created at
 * that same path inherits the corpse.
 *
 * Unmounting the staged entry is the whole fix: Docker Desktop re-stages it
 * from the live inode on the next container create.
 */
readonly class StaleBindMount
{
    public function __construct(
        /** The host path Docker Desktop staged, e.g. /home/rob/project/docker/php/local.ini */
        public string $hostPath,
        /** The staged mount point inside the distro. */
        public string $mountPoint,
        /** WSL distro name the staging directory belongs to, e.g. "Ubuntu". */
        public string $distro,
        /** sha256 of {@see $hostPath}, which is how Docker Desktop names the entry. */
        public string $hash,
        /** Why we consider it stale — shown to the user. */
        public StaleBindMountReason $reason,
    ) {
    }

    /**
     * Docker Desktop names each staged mount after the sha256 of the host path
     * it stages, with no trailing newline. Verified against a live
     * Docker Desktop 4.x / WSL2 install.
     */
    public static function hashForHostPath(string $hostPath): string
    {
        return hash('sha256', $hostPath);
    }

    public function describe(): string
    {
        return sprintf('%s (%s)', $this->hostPath, $this->reason->describe());
    }

    public function unmountCommand(): string
    {
        return 'sudo umount ' . escapeshellarg($this->mountPoint);
    }
}
