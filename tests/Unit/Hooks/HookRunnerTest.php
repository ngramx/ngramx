<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Hooks;

use Ngramx\Config\Schema\HookDefinition;
use Ngramx\Config\Schema\HookEvent;
use Ngramx\Config\Schema\HooksConfig;
use Ngramx\Hooks\HookRunner;
use Ngramx\Output\OutputFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class HookRunnerTest extends TestCase
{
    private string $cwd;
    private HookRunner $runner;

    protected function setUp(): void
    {
        $this->cwd = sys_get_temp_dir() . '/ngramx-hook-runner-' . uniqid('', true);
        mkdir($this->cwd, 0755, true);
        $this->runner = new HookRunner();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->cwd)) {
            $files = scandir($this->cwd) ?: [];
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                @unlink($this->cwd . '/' . $file);
            }
            @rmdir($this->cwd);
        }
    }

    public function test_it_interpolates_placeholders_and_runs_command(): void
    {
        $marker = $this->cwd . DIRECTORY_SEPARATOR . 'ok.txt';
        $config = new HooksConfig([
            HookEvent::WorktreeCreate->value => [
                new HookDefinition(
                    command: $this->phpWriteFileCommand($marker, 'from-{ticket}'),
                ),
            ],
        ]);

        $ok = $this->runner->run(
            $config,
            HookEvent::WorktreeCreate,
            ['ticket' => 'gig-1', 'worktree_path' => $this->cwd],
            $this->cwd,
        );

        $this->assertTrue($ok);
        $this->assertFileExists($marker);
        $this->assertSame('from-gig-1', trim((string) file_get_contents($marker)));
    }

    public function test_failed_hook_is_ignored_by_default(): void
    {
        $config = new HooksConfig([
            HookEvent::EnvironmentUp->value => [
                new HookDefinition(command: $this->phpExitCommand(1)),
            ],
        ]);

        $formatter = new OutputFormatter(new BufferedOutput());
        $ok = $this->runner->run(
            $config,
            HookEvent::EnvironmentUp,
            [],
            $this->cwd,
            $formatter,
        );

        $this->assertTrue($ok);
    }

    public function test_failed_hook_can_abort(): void
    {
        $config = new HooksConfig([
            HookEvent::EnvironmentUp->value => [
                new HookDefinition(
                    command: $this->phpExitCommand(2),
                    ignoreFailure: false,
                ),
            ],
        ]);

        $ok = $this->runner->run(
            $config,
            HookEvent::EnvironmentUp,
            [],
            $this->cwd,
        );

        $this->assertFalse($ok);
    }

    private function phpWriteFileCommand(string $path, string $contents): string
    {
        return escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
            'file_put_contents(' . var_export($path, true) . ', ' . var_export($contents, true) . ');'
        );
    }

    private function phpExitCommand(int $code): string
    {
        return escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg("exit({$code});");
    }
}
