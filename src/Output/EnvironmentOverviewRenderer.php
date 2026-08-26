<?php

declare(strict_types=1);

namespace Ngramx\Output;

use Ngramx\Codabyte\CloudRun;
use Ngramx\Codabyte\CloudRunsResult;
use Ngramx\Worktree\AgentRun;
use Ngramx\Worktree\EnvironmentSnapshot;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders the repository-wide environment overview: where the project lives,
 * whether the main checkout's environment is up, and a numbered table of every
 * worktree with its branch, state and URL.
 *
 * The numbering is what `ngramx worktree --cleanup <#>` accepts.
 */
class EnvironmentOverviewRenderer
{
    private const COLOR_SMOKE = '#D2DCE5';

    public function __construct(
        private readonly OutputInterface $output,
        private readonly OutputFormatter $formatter,
    ) {
    }

    /**
     * @param list<EnvironmentSnapshot> $worktrees
     */
    public function render(EnvironmentSnapshot $root, array $worktrees, ?CloudRunsResult $cloud = null): void
    {
        $this->renderProject($root);
        $this->renderWorktrees($worktrees);

        if ($cloud !== null) {
            $this->renderCloudRuns($cloud);
        }
    }

    /**
     * The environments Codabyte is running for this repository.
     *
     * Three outcomes, deliberately distinguished:
     *
     * - not configured: nothing at all, because most people are not running a
     *   cloud agent and an empty section would just be noise;
     * - unreachable: one dim line, because silence would read as "nothing
     *   running there", which is precisely what we failed to find out;
     * - answered: the list, or an explicit "none".
     */
    private function renderCloudRuns(CloudRunsResult $cloud): void
    {
        if (!$cloud->configured) {
            return;
        }

        $this->formatter->section('Cloud (Codabyte)');
        $this->output->writeln('');

        if ($cloud->failed()) {
            $this->output->writeln(sprintf(
                '  <fg=' . self::COLOR_SMOKE . '>unavailable — %s</>',
                OutputFormatter::escape((string) $cloud->error)
            ));
            $this->output->writeln('');

            return;
        }

        if ($cloud->runs === []) {
            $this->formatter->info('No environments running on the server for this repository.');
            $this->output->writeln('');

            return;
        }

        $nameWidth = $this->cloudColumnWidth($cloud->runs, static fn (CloudRun $r): string => $r->name, 20);
        $branchWidth = $this->cloudColumnWidth($cloud->runs, static fn (CloudRun $r): string => $r->branch ?? '—', 20);

        $this->output->writeln(sprintf(
            '  <fg=' . self::COLOR_SMOKE . '>%s  %s  %-7s  %s</>',
            $this->pad('environment', $nameWidth),
            $this->pad('branch', $branchWidth),
            'status',
            'agent',
        ));

        foreach ($cloud->runs as $run) {
            $state = $run->running ? 'running' : 'stopped';

            $this->output->writeln(sprintf(
                '  %s  %s  %s  %s',
                $this->pad(OutputFormatter::escape($run->name), $nameWidth),
                $this->pad(OutputFormatter::escape($run->branch ?? '—'), $branchWidth),
                $this->colourState($state, $run->running) . str_repeat(' ', max(0, 7 - mb_strwidth($state))),
                $this->colourAgentState($run->agentState),
            ));
        }

        $this->output->writeln('');
    }

    private function renderProject(EnvironmentSnapshot $root): void
    {
        $this->formatter->section('Project');
        $this->output->writeln('');

        $this->line('path', $root->path);
        $this->line('branch', $root->branch ?? '—');
        $this->line('environment', $this->describeState($root));

        if ($root->url !== null) {
            $this->line('url', $root->url);
        }

        if ($root->agent !== null) {
            $this->line('agent', $this->colourAgentState($root->agent->state()) . $this->describeAgent($root->agent));
        }

        if ($root->isCurrent) {
            $this->output->writeln('');
            $this->formatter->info('You are in the main checkout.');
        }
    }

    /**
     * @param list<EnvironmentSnapshot> $worktrees
     */
    private function renderWorktrees(array $worktrees): void
    {
        if ($worktrees === []) {
            $this->formatter->section('Worktrees');
            $this->output->writeln('');
            $this->formatter->info('None yet — create one with: ngramx worktree <ticket>');
            $this->output->writeln('');
            return;
        }

        $this->formatter->section('Worktrees (' . count($worktrees) . ')');
        $this->output->writeln('');

        $nameWidth = $this->columnWidth($worktrees, static fn (EnvironmentSnapshot $w): string => $w->name, 20);
        $branchWidth = $this->columnWidth($worktrees, static fn (EnvironmentSnapshot $w): string => $w->branch ?? '—', 20);

        // The agent column only appears once something has actually recorded a
        // run. Most people are not driving Ngramx from an agent runner, and an
        // always-present column of dashes would cost them width for nothing.
        $agentWidth = $this->hasAgentRuns($worktrees)
            ? $this->columnWidth($worktrees, static fn (EnvironmentSnapshot $w): string => $w->agent?->state() ?? '—', 5)
            : 0;

        $this->output->writeln(sprintf(
            '  <fg=' . self::COLOR_SMOKE . '>%s %-3s %s  %s  %-7s  %s%s</>',
            ' ',
            '#',
            $this->pad('worktree', $nameWidth),
            $this->pad('branch', $branchWidth),
            'status',
            $agentWidth > 0 ? $this->pad('agent', $agentWidth) . '  ' : '',
            'url',
        ));

        foreach ($worktrees as $i => $worktree) {
            // The marker is what makes the overview readable from inside a
            // worktree: the developer can see which row is the one they are
            // standing in without comparing paths by eye.
            $marker = $worktree->isCurrent ? '<fg=' . self::COLOR_SMOKE . '>❯</>' : ' ';

            // The state is padded before it is coloured: style tags do not
            // occupy screen columns, so padding the tagged string would push
            // the url column out of line.
            $state = $worktree->running ? 'running' : 'stopped';

            // Pad on display width, not bytes: a multi-byte placeholder like
            // "—" is one column wide but three bytes, and sprintf's %-Ns pads
            // by bytes — which would pull the following columns out of line.
            $agentState = $worktree->agent?->state() ?? '—';

            $this->output->writeln(sprintf(
                '  %s <fg=yellow>%-3d</> %s  %s  %s  %s%s',
                $marker,
                $i + 1,
                $this->pad($worktree->name, $nameWidth),
                $this->pad(OutputFormatter::escape($worktree->branch ?? '—'), $branchWidth),
                $this->colourState($state, $worktree->running) . str_repeat(' ', max(0, 7 - mb_strwidth($state))),
                $agentWidth > 0
                    ? $this->colourAgentState($agentState)
                        . str_repeat(' ', max(0, $agentWidth - mb_strwidth($agentState)))
                        . '  '
                    : '',
                $worktree->url ?? '—',
            ));
        }

        $this->output->writeln('');
        $this->formatter->info('Clean up: ngramx worktree --cleanup [<#>|<ticket>|<text>] (no argument to choose from a list)');
        $this->output->writeln('');
    }

    private function describeState(EnvironmentSnapshot $environment): string
    {
        return $this->colourState($environment->running ? 'running' : 'stopped', $environment->running);
    }

    private function colourState(string $state, bool $running): string
    {
        return $running ? "<fg=green>$state</>" : "<fg=yellow>$state</>";
    }

    /**
     * The trailing detail after an agent state on the project line: who ran it
     * and which issue, when the runner recorded them.
     */
    private function describeAgent(AgentRun $agent): string
    {
        $parts = array_filter([$agent->issue, $agent->source]);

        if ($parts === []) {
            return '';
        }

        return ' <fg=' . self::COLOR_SMOKE . '>('
            . OutputFormatter::escape(implode(' · ', $parts))
            . ')</>';
    }

    /**
     * @param list<EnvironmentSnapshot> $worktrees
     */
    private function hasAgentRuns(array $worktrees): bool
    {
        foreach ($worktrees as $worktree) {
            if ($worktree->agent !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Colour a recorded agent state.
     *
     * `started` is deliberately not green: read from a marker file, it means the
     * runner recorded a beginning and never a matching end, which is a run in
     * progress *or* one that died without cleaning up. Neutral cyan keeps the
     * overview from asserting the difference.
     *
     * `running` is green because it only ever comes from the server, which
     * derives it from the live process rather than from a file, and so can be
     * believed.
     */
    private function colourAgentState(string $state): string
    {
        return match ($state) {
            'succeeded', 'running' => "<fg=green>$state</>",
            'failed' => "<fg=red>$state</>",
            'started' => "<fg=cyan>$state</>",
            'none', '—' => "<fg=" . self::COLOR_SMOKE . ">$state</>",
            default => "<fg=yellow>$state</>",
        };
    }

    /**
     * Right-pad $value to $width display columns.
     */
    private function pad(string $value, int $width): string
    {
        return $value . str_repeat(' ', max(0, $width - mb_strwidth($value)));
    }

    private function line(string $label, string $value): void
    {
        $this->output->writeln(sprintf(
            '  <fg=' . self::COLOR_SMOKE . '>%-12s</> %s',
            $label,
            $value,
        ));
    }

    /**
     * @param list<CloudRun> $runs
     * @param callable(CloudRun): string $value
     */
    private function cloudColumnWidth(array $runs, callable $value, int $minimum): int
    {
        $width = $minimum;
        foreach ($runs as $run) {
            $width = max($width, mb_strwidth($value($run)));
        }

        return $width;
    }

    /**
     * @param list<EnvironmentSnapshot> $worktrees
     * @param callable(EnvironmentSnapshot): string $value
     */
    private function columnWidth(array $worktrees, callable $value, int $minimum): int
    {
        $width = $minimum;
        foreach ($worktrees as $worktree) {
            $width = max($width, mb_strwidth($value($worktree)));
        }

        return $width;
    }
}
