<?php

declare(strict_types=1);

namespace Ngramx\Worktree;

/**
 * Resolve a live Cursor/VS Code IPC socket for the WSL remote CLI.
 *
 * The integrated terminal sets VSCODE_IPC_HOOK_CLI when Cursor starts, but that
 * socket can disappear while a long-running command (like `ngramx worktree`) is
 * still running. The remote `cursor` CLI then fails with ENOENT/ECONNREFUSED.
 */
final class CursorIpcHookResolver
{
    public const WSL_RUNTIME_DIR = '/mnt/wslg/runtime-dir';

    /**
     * Candidate IPC hook paths, most likely first.
     *
     * @return list<string>
     */
    public static function discoverCandidates(?string $currentHook = null): array
    {
        return self::buildCandidates($currentHook ?? self::currentHook(), self::discoverSockets());
    }

    /**
     * @param list<string> $sockets
     *
     * @return list<string>
     */
    public static function buildCandidates(?string $currentHook, array $sockets): array
    {
        $candidates = [];

        if ($currentHook !== null && is_file($currentHook)) {
            $candidates[] = $currentHook;
        }

        foreach ($sockets as $socket) {
            if (!in_array($socket, $candidates, true)) {
                $candidates[] = $socket;
            }
        }

        return $candidates;
    }

    /**
     * @return list<string>
     */
    public static function discoverSockets(): array
    {
        $sockets = glob(self::WSL_RUNTIME_DIR . '/vscode-ipc-*.sock') ?: [];
        usort(
            $sockets,
            static fn (string $a, string $b): int => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0)
        );

        return $sockets;
    }

    public static function isIpcConnectionFailure(string $output): bool
    {
        return str_contains($output, 'Unable to connect to VS Code server')
            || str_contains($output, 'vscode-ipc-')
            || str_contains($output, 'ENOENT')
            || str_contains($output, 'ECONNREFUSED');
    }

    private static function currentHook(): ?string
    {
        $current = getenv('VSCODE_IPC_HOOK_CLI');

        if (!is_string($current) || $current === '') {
            return null;
        }

        return $current;
    }
}
