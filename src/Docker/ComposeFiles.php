<?php

declare(strict_types=1);

namespace Ngramx\Docker;

/**
 * Resolves the ordered list of `-f <file>` arguments for a docker-compose
 * invocation.
 *
 * Three layers are stacked, lowest priority first (Docker Compose merges later
 * files on top of earlier ones):
 *
 *   1. The base compose file (`docker-compose.yml`).
 *   2. The Ngramx-generated override (`docker-compose.override.yml`) — port
 *      offsets, namespace prefixes, the worktree git bind mount. This file is
 *      regenerated on every `ngramx up` / `ngramx review`, so it must NOT be
 *      hand-edited; any manual changes are silently lost on the next run.
 *   3. An optional, never-regenerated user override (`docker-compose.user.yml`).
 *      Ngramx always layers this last when it exists, so local customisations
 *      (extra mounts, env vars, services) win and survive override regeneration.
 *
 * Centralising the file list here guarantees every code path that shells out to
 * docker-compose (up/down/ps/logs/exec/config/health checks) applies the same
 * merge order — in particular that the user override is never dropped.
 */
class ComposeFiles
{
    public const OVERRIDE_FILE = 'docker-compose.override.yml';
    public const USER_FILE = 'docker-compose.user.yml';

    /**
     * Build the ordered `-f` argument list for a docker-compose command.
     *
     * @return list<string> e.g. ['-f', 'docker-compose.yml', '-f', 'docker-compose.override.yml']
     */
    public static function fileArgs(string $composeFile): array
    {
        $args = ['-f', $composeFile];

        foreach (self::layeredFiles($composeFile) as $file) {
            $args[] = '-f';
            $args[] = $file;
        }

        return $args;
    }

    /**
     * The optional override files that exist alongside the base compose file,
     * in apply order (generated override first, then the user override).
     *
     * @return list<string>
     */
    public static function layeredFiles(string $composeFile): array
    {
        $dir = dirname($composeFile);
        $files = [];

        $override = $dir . '/' . self::OVERRIDE_FILE;
        if (file_exists($override)) {
            $files[] = $override;
        }

        $user = $dir . '/' . self::USER_FILE;
        if (file_exists($user)) {
            $files[] = $user;
        }

        return $files;
    }
}
