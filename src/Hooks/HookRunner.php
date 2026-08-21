<?php

declare(strict_types=1);

namespace Ngramx\Hooks;

use Ngramx\Config\Schema\HookDefinition;
use Ngramx\Config\Schema\HookEvent;
use Ngramx\Config\Schema\HooksConfig;
use Ngramx\Output\OutputFormatter;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs host commands configured for a lifecycle event.
 *
 * Commands may include `{placeholder}` tokens substituted from the context map
 * (e.g. `{worktree_path}`, `{branch}`, `{url}`). Context values interpolated into
 * the shell command line are escaped with {@see escapeshellarg()} so metacharacters
 * in git refs or paths cannot break out of the intended command. cwd placeholders
 * are substituted without shell escaping (they are not passed through a shell).
 */
class HookRunner
{
    /**
     * @param array<string, string> $context
     *
     * @return bool False when a non-ignorable hook failed
     */
    public function run(
        HooksConfig $config,
        HookEvent $event,
        array $context,
        string $defaultCwd,
        ?OutputFormatter $formatter = null,
    ): bool {
        $hooks = $config->for($event);
        if ($hooks === []) {
            return true;
        }

        $formatter?->section('Running ' . $event->value . ' hooks');

        $ok = true;
        foreach ($hooks as $index => $hook) {
            if (!$this->runOne($hook, $index, $context, $defaultCwd, $formatter)) {
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * @param array<string, string> $context
     */
    private function runOne(
        HookDefinition $hook,
        int $index,
        array $context,
        string $defaultCwd,
        ?OutputFormatter $formatter,
    ): bool {
        $command = $this->interpolate($hook->command, $context, escapeForShell: true);
        $cwd = $hook->cwd !== null
            ? $this->interpolate($hook->cwd, $context, escapeForShell: false)
            : $defaultCwd;

        $label = $hook->description !== ''
            ? $hook->description
            : $command;

        $formatter?->info('Hook [' . ($index + 1) . ']: ' . $label);

        $process = Process::fromShellCommandline($command, $cwd);
        $process->setTimeout($hook->timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $formatter?->warning("Hook timed out after {$hook->timeout}s: {$command}");
            return $hook->ignoreFailure;
        }

        if ($process->isSuccessful()) {
            return true;
        }

        $detail = trim($process->getErrorOutput());
        if ($detail === '') {
            $detail = trim($process->getOutput());
        }
        if ($detail === '') {
            $detail = 'exit code ' . ($process->getExitCode() ?? -1);
        }

        if ($hook->ignoreFailure) {
            $formatter?->warning("Hook failed (ignored): {$detail}");

            return true;
        }

        $formatter?->error("Hook failed: {$detail}");

        return false;
    }

    /**
     * @param array<string, string> $context
     */
    private function interpolate(string $value, array $context, bool $escapeForShell): string
    {
        foreach ($context as $key => $replacement) {
            if ($escapeForShell) {
                $replacement = escapeshellarg($replacement);
            }
            $value = str_replace('{' . $key . '}', $replacement, $value);
        }

        return $value;
    }
}
