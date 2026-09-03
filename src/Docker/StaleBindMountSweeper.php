<?php

declare(strict_types=1);

namespace Ngramx\Docker;

use Ngramx\Output\OutputFormatter;
use Symfony\Component\Process\Process;

/**
 * Finds and clears dangling entries in Docker Desktop's WSL bind-mount
 * staging area — see {@see StaleBindMount} for what they are and how they
 * come about.
 *
 * Two entry points, both no-ops off Docker Desktop/WSL:
 *
 *   - {@see sweepUnder()} runs before `docker compose up` and clears the
 *     corpses staged for paths under the project (or worktree) we are about to
 *     start. Cheap: one read of `/proc/self/mountinfo`, no docker calls.
 *   - {@see recoverFromFailure()} runs when a start has already failed, and
 *     targets the exact staged paths the engine named in its error. Trusted
 *     over our own heuristics, since the engine is the one that couldn't
 *     resolve them.
 *
 * Clearing needs root, because it is a real `umount`. In order: unmount
 * directly if we are already root (`sudo ngramx up`), then passwordless
 * `sudo -n`, then an interactive `sudo` when there is a TTY to prompt on.
 * With none of those available we print the exact command instead of hanging
 * a headless run on a password prompt — which is the case that matters for
 * Codabyte, where nobody is watching stdin.
 */
class StaleBindMountSweeper
{
    /**
     * The path segment that identifies Docker Desktop's staging area. Matched
     * as a segment rather than a full prefix because the distro-side view
     * (`/mnt/wsl/docker-desktop-bind-mounts/…`) and the engine-side view
     * (`/run/desktop/mnt/host/wsl/docker-desktop-bind-mounts/…`) differ in
     * everything but this.
     */
    private const STAGING_SEGMENT = 'docker-desktop-bind-mounts';

    /**
     * Linux appends this to the mount root in `/proc/self/mountinfo` when the
     * mounted dentry has been unlinked.
     */
    private const DELETED_SUFFIX = '//deleted';

    public function __construct(
        private readonly string $mountInfoPath = '/proc/self/mountinfo',
    ) {
    }

    /**
     * Every dead staged mount on this machine, in mount-table order.
     *
     * @return list<StaleBindMount>
     */
    public function findAll(): array
    {
        $stale = [];

        foreach ($this->stagedMounts() as [$hostPath, $mountPoint, $distro, $hash, $rootIsDeleted]) {
            $reason = match (true) {
                $rootIsDeleted => StaleBindMountReason::DeletedInode,
                !file_exists($hostPath) => StaleBindMountReason::MissingHostPath,
                default => null,
            };

            if ($reason === null) {
                continue;
            }

            $stale[] = new StaleBindMount($hostPath, $mountPoint, $distro, $hash, $reason);
        }

        return $stale;
    }

    /**
     * Dead staged mounts for host paths inside $root (the project directory or
     * a worktree). Scoped deliberately: a dead entry elsewhere may still be
     * mounted into somebody else's *running* container, and these mounts are
     * shared-propagation, so an unmount here can reach that container's
     * namespace. Only the paths we are about to (re)create containers for are
     * ours to clear.
     *
     * @return list<StaleBindMount>
     */
    public function findUnder(string $root): array
    {
        $prefix = rtrim($root, '/');
        if ($prefix === '') {
            return [];
        }

        return array_values(array_filter(
            $this->findAll(),
            static fn (StaleBindMount $mount): bool => $mount->hostPath === $prefix
                || str_starts_with($mount->hostPath, $prefix . '/'),
        ));
    }

    /**
     * The staged mounts a failed container start named in its error output.
     *
     * The engine reports the paths in its own namespace
     * (`/run/desktop/mnt/host/wsl/…`), so we match on the `<distro>/<hash>`
     * tail, which is identical on both sides.
     *
     * @return list<StaleBindMount>
     */
    public function findForFailure(string $errorOutput): array
    {
        if (!str_contains($errorOutput, self::STAGING_SEGMENT)) {
            return [];
        }

        $pattern = '#' . preg_quote(self::STAGING_SEGMENT, '#') . '/([^/"\s]+)/([0-9a-f]{64})#';
        if (preg_match_all($pattern, $errorOutput, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $wanted = [];
        foreach ($matches as $match) {
            $wanted[$match[1] . '/' . $match[2]] = true;
        }

        $found = [];
        foreach ($this->stagedMounts() as [$hostPath, $mountPoint, $distro, $hash, $rootIsDeleted]) {
            if (!isset($wanted[$distro . '/' . $hash])) {
                continue;
            }

            $found[] = new StaleBindMount(
                $hostPath,
                $mountPoint,
                $distro,
                $hash,
                match (true) {
                    $rootIsDeleted => StaleBindMountReason::DeletedInode,
                    !file_exists($hostPath) => StaleBindMountReason::MissingHostPath,
                    default => StaleBindMountReason::EngineReportedMissing,
                },
            );
        }

        return $found;
    }

    /**
     * Clear the dead staged mounts for $root before starting its containers.
     * Silent when there is nothing to do, so the common path prints nothing.
     */
    public function sweepUnder(string $root, OutputFormatter $formatter): void
    {
        $stale = $this->findUnder($root);
        if ($stale === []) {
            return;
        }

        $formatter->info(sprintf(
            'Clearing %d stale Docker Desktop bind mount(s) left over from replaced files or worktrees...',
            count($stale),
        ));

        $this->clearAndReport($stale, $formatter);
    }

    /**
     * Attempt recovery after a container start failed on a staged bind mount.
     *
     * Returns true when at least one entry was cleared, i.e. when retrying the
     * start is worth the caller's time.
     */
    public function recoverFromFailure(string $errorOutput, OutputFormatter $formatter): bool
    {
        $stale = $this->findForFailure($errorOutput);
        if ($stale === []) {
            return false;
        }

        $formatter->warning('Docker could not resolve a staged bind mount. This is the Docker Desktop/WSL');
        $formatter->warning('staging area holding a file that was replaced on the host (a git checkout, say).');

        return $this->clearAndReport($stale, $formatter) > 0;
    }

    /**
     * Unmount each entry, reporting what could not be cleared along with the
     * command the user can run themselves.
     *
     * @param list<StaleBindMount> $stale
     * @return int Number of entries actually cleared.
     */
    private function clearAndReport(array $stale, OutputFormatter $formatter): int
    {
        $cleared = 0;
        $failed = [];

        foreach ($stale as $mount) {
            if ($this->unmount($mount)) {
                $cleared++;
                continue;
            }

            $failed[] = $mount;
        }

        if ($cleared > 0) {
            $formatter->info(sprintf('  Cleared %d stale mount(s)', $cleared));
        }

        if ($failed !== []) {
            $formatter->warning('Could not clear these stale Docker Desktop bind mounts (removing them needs root):');
            foreach ($failed as $mount) {
                $formatter->warning('  ' . $mount->describe());
                $formatter->warning('    ' . $mount->unmountCommand());
            }
            $formatter->warning('Run the command(s) above, then re-run this command.');
        }

        return $cleared;
    }

    /**
     * Unmount one staged entry, escalating only as far as the environment
     * allows. Returns true once the mount point is gone from the table.
     */
    protected function unmount(StaleBindMount $mount): bool
    {
        foreach ($this->unmountCommands() as $command) {
            $process = new Process([...$command, $mount->mountPoint]);
            $process->setTimeout(15);

            if ($command === ['sudo', 'umount']) {
                $this->enableTtyIfAvailable($process);
            }

            try {
                $process->run();
            } catch (\Throwable) {
                continue;
            }

            if ($process->isSuccessful()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `umount` invocations to try, in order of least privilege escalation.
     *
     * @return list<list<string>>
     */
    protected function unmountCommands(): array
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            return [['umount']];
        }

        $commands = [['sudo', '-n', 'umount']];

        if ($this->hasTty()) {
            // Only worth prompting when somebody is there to type a password;
            // a headless caller (Codabyte, CI) gets the printed command instead.
            $commands[] = ['sudo', 'umount'];
        }

        return $commands;
    }

    /**
     * Parse the staging-area entries out of the mount table.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: bool}>
     *         [host path, staged mount point, distro, hash, root-is-deleted]
     */
    private function stagedMounts(): array
    {
        if (!is_readable($this->mountInfoPath)) {
            return [];
        }

        $contents = @file_get_contents($this->mountInfoPath);
        if (!is_string($contents)) {
            return [];
        }

        $mounts = [];

        foreach (explode("\n", $contents) as $line) {
            $fields = explode(' ', trim($line));
            if (count($fields) < 5) {
                continue;
            }

            $root = $this->unescapeMountField($fields[3]);
            $mountPoint = $this->unescapeMountField($fields[4]);

            if (!preg_match(
                '#/' . preg_quote(self::STAGING_SEGMENT, '#') . '/([^/]+)/([0-9a-f]{64})$#',
                $mountPoint,
                $match,
            )) {
                continue;
            }

            $rootIsDeleted = str_ends_with($root, self::DELETED_SUFFIX);
            $hostPath = $rootIsDeleted
                ? substr($root, 0, -strlen(self::DELETED_SUFFIX))
                : $root;

            $mounts[] = [$hostPath, $mountPoint, $match[1], $match[2], $rootIsDeleted];
        }

        return $mounts;
    }

    /**
     * `/proc/self/mountinfo` octal-escapes space, tab, newline and backslash in
     * the root and mount-point fields.
     */
    private function unescapeMountField(string $value): string
    {
        return (string) preg_replace_callback(
            '/\\\\([0-7]{3})/',
            static fn (array $m): string => chr((int) octdec($m[1])),
            $value,
        );
    }

    /**
     * Whether an interactive password prompt would actually reach a human.
     * Mirrors {@see \Ngramx\Command\SecureCommand} so behaviour is consistent
     * across the places ngramx escalates to sudo.
     */
    protected function hasTty(): bool
    {
        try {
            return Process::isTtySupported() && \defined('STDIN') && @stream_isatty(\STDIN);
        } catch (\Throwable) {
            return false;
        }
    }

    private function enableTtyIfAvailable(Process $process): void
    {
        try {
            if ($this->hasTty()) {
                $process->setTty(true);
            }
        } catch (\Throwable) {
            // Some environments report TTY support inaccurately; fall back to a
            // non-interactive run rather than crashing.
        }
    }
}
