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
 * (e.g. `{worktree_path}`, `{branch}`, `{url}`).
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
        $command = $this->interpolate($hook->command, $context);
        $cwd = $hook->cwd !== null
            ? $this->interpolate($hook->cwd, $context)
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
    private function interpolate(string $value, array $context): string
    {
        foreach ($context as $key => $replacement) {
            $value = str_replace('{' . $key . '}', $replacement, $value);
        }

        return $value;
    }
}
