<?php

declare(strict_types=1);

namespace Ngramx\Output;

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
    public function render(EnvironmentSnapshot $root, array $worktrees): void
    {
        $this->renderProject($root);
        $this->renderWorktrees($worktrees);
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

        $this->output->writeln(sprintf(
            '  <fg=' . self::COLOR_SMOKE . '>%s %-3s %s  %s  %-7s  %s</>',
            ' ',
            '#',
            $this->pad('worktree', $nameWidth),
            $this->pad('branch', $branchWidth),
            'status',
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
            $this->output->writeln(sprintf(
                '  %s <fg=yellow>%-3d</> %s  %s  %s  %s',
                $marker,
                $i + 1,
                $this->pad($worktree->name, $nameWidth),
                $this->pad(OutputFormatter::escape($worktree->branch ?? '—'), $branchWidth),
                $this->colourState($state, $worktree->running) . str_repeat(' ', max(0, 7 - mb_strwidth($state))),
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
