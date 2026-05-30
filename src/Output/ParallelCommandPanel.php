<?php

declare(strict_types=1);

namespace Ngramx\Output;

use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Live panel for rendering a group of concurrently running commands.
 *
 * Each command is displayed as a single line: `<label>: <last log line>`.
 * Labels are padded so the colons align. As each command finishes its slot
 * disappears from the panel on the next render; once every command is done
 * the caller should call close() to wipe the section entirely.
 *
 * When the output is not a ConsoleOutput (e.g. in tests or dumb terminals),
 * the panel falls back to streaming `<label>: <line>` prefixed log lines to
 * the fallback OutputInterface so no information is lost.
 */
class ParallelCommandPanel
{
    private const COLOR_LABEL = OutputFormatter::COLOR_TEAL;
    private const COLOR_LOG = OutputFormatter::COLOR_SMOKE;
    private const MAX_LINE_WIDTH = 120;

    /** @var list<string> */
    private array $labels;

    /** @var array<string, string> label => latest sanitised log line */
    private array $latest = [];

    /** @var array<string, bool> label => finished */
    private array $finished = [];

    private int $labelWidth;

    private bool $closed = false;

    /**
     * @param list<string> $labels Ordered list of unique labels.
     */
    public function __construct(
        private readonly ?ConsoleSectionOutput $section,
        array $labels,
        private readonly ?OutputInterface $fallback = null,
    ) {
        $this->labels = $labels;
        foreach ($this->labels as $label) {
            $this->latest[$label] = '';
            $this->finished[$label] = false;
        }

        $max = 0;
        foreach ($this->labels as $label) {
            $max = max($max, mb_strlen($label));
        }
        $this->labelWidth = $max;
    }

    public function isActive(): bool
    {
        return $this->section !== null && !$this->closed;
    }

    /**
     * Record a new log line for the given label and re-render.
     */
    public function updateLine(string $label, string $line): void
    {
        if ($this->closed) {
            return;
        }

        if (!array_key_exists($label, $this->latest)) {
            return;
        }

        $clean = $this->sanitise($line);
        if ($clean === '') {
            return;
        }

        $this->latest[$label] = $clean;

        if ($this->section === null) {
            $this->fallback?->writeln($this->formatLine($label, $clean));
            return;
        }

        $this->render();
    }

    /**
     * Mark a label as finished; its slot will be dropped on the next render.
     */
    public function markFinished(string $label): void
    {
        if ($this->closed || !array_key_exists($label, $this->finished)) {
            return;
        }

        $this->finished[$label] = true;

        if ($this->section === null) {
            return;
        }

        $this->render();
    }

    /**
     * Clear the panel entirely from the console so no trace is left.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->section?->clear();
        $this->closed = true;
    }

    private function render(): void
    {
        if ($this->section === null) {
            return;
        }

        $rendered = [];
        foreach ($this->labels as $label) {
            if ($this->finished[$label]) {
                continue;
            }
            $rendered[] = $this->formatLine($label, $this->latest[$label]);
        }

        if ($rendered === []) {
            // Keep a blank line so the next overwrite() has something to replace
            // and clear() actually removes the section contents.
            $rendered[] = '';
        }

        $this->section->overwrite($rendered);
    }

    private function formatLine(string $label, string $line): string
    {
        $paddedLabel = str_pad($label, $this->labelWidth);
        $displayLine = $line === '' ? '…' : $line;
        $displayLine = $this->truncate($displayLine);

        return sprintf(
            '  <fg=%s>%s</>: <fg=%s>%s</>',
            self::COLOR_LABEL,
            $paddedLabel,
            self::COLOR_LOG,
            $displayLine,
        );
    }

    private function sanitise(string $line): string
    {
        $line = preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $line) ?? $line;
        $line = str_replace(["\r", "\t"], ['', '  '], $line);
        return trim($line);
    }

    private function truncate(string $line): string
    {
        $budget = self::MAX_LINE_WIDTH - $this->labelWidth - 4; // "  " indent + ": "
        if ($budget < 20) {
            $budget = 20;
        }
        if (mb_strlen($line) > $budget) {
            return mb_substr($line, 0, $budget - 1) . '…';
        }
        return $line;
    }
}
