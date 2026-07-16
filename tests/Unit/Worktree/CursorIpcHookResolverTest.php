<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Worktree;

use Ngramx\Worktree\CursorIpcHookResolver;
use PHPUnit\Framework\TestCase;

class CursorIpcHookResolverTest extends TestCase
{
    public function test_buildCandidates_skips_missing_current_hook_and_preserves_socket_order(): void
    {
        $tmp = sys_get_temp_dir() . '/ngramx-cursor-ipc-' . uniqid();
        mkdir($tmp, 0755, true);

        $stale = $tmp . '/stale.sock';
        $older = $tmp . '/vscode-ipc-older.sock';
        $newer = $tmp . '/vscode-ipc-newer.sock';
        touch($older);
        touch($newer);

        $candidates = CursorIpcHookResolver::buildCandidates($stale, [$newer, $older]);

        $this->assertSame([$newer, $older], $candidates);

        unlink($older);
        unlink($newer);
        rmdir($tmp);
    }

    public function test_buildCandidates_prefers_existing_current_hook(): void
    {
        $tmp = sys_get_temp_dir() . '/ngramx-cursor-ipc-' . uniqid();
        mkdir($tmp, 0755, true);

        $current = $tmp . '/vscode-ipc-current.sock';
        $other = $tmp . '/vscode-ipc-other.sock';
        touch($current);
        touch($other);

        $candidates = CursorIpcHookResolver::buildCandidates($current, [$other]);

        $this->assertSame([$current, $other], $candidates);

        unlink($current);
        unlink($other);
        rmdir($tmp);
    }

    public function test_isIpcConnectionFailure_detects_wsl_remote_cli_errors(): void
    {
        $this->assertTrue(CursorIpcHookResolver::isIpcConnectionFailure(
            'Unable to connect to VS Code server: Error in request.'
            . "\nError: connect ENOENT /mnt/wslg/runtime-dir/vscode-ipc-dead.sock"
        ));
        $this->assertTrue(CursorIpcHookResolver::isIpcConnectionFailure(
            'Error: connect ECONNREFUSED /mnt/wslg/runtime-dir/vscode-ipc-live.sock'
        ));
        $this->assertFalse(CursorIpcHookResolver::isIpcConnectionFailure('cursor: command not found'));
    }
}
