<?php

declare(strict_types=1);

namespace Ngramx\Output;

use Symfony\Component\Console\Output\ConsoleSectionOutput;

/**
 * Renders a fixed-height, rolling live log panel into a console section.
 *
 * The panel shows the most recent N lines of output in dim grey, updating
 * in place as new lines arrive. When cleared, the panel disappears entirely
 * from the console, leaving no trace behind.
 *
 * Partial lines received from a streaming Process::OUT/Process::ERR callback
 * are buffered until a newline is seen, so the display only updates on
 * complete lines.
 */
class LiveLogPanel
{
    private const COLOR_DIM = OutputFormatter::COLOR_SMOKE;

    /** @var list<string> */
    private array $lines = [];

    private string $pendingBuffer = '';

    private bool $closed = false;

    public function __construct(
        private readonly ?ConsoleSectionOutput $section,
        private readonly int $maxLines = 3,
        private readonly string $indent = '  ',
    ) {
    }

    /**
     * Append a buffer that may contain any number of partial or complete lines.
     *
     * Safe to call from a Symfony Process output callback, where each invocation
     * may deliver a chunk in the middle of a line.
     */
    public function appendBuffer(string $buffer): void
    {
        if ($this->closed) {
            return;
        }

        $this->pendingBuffer .= $buffer;

        while (true) {
            $pos = strcspn($this->pendingBuffer, "\r\n");
            if ($pos === strlen($this->pendingBuffer)) {
                return;
            }

            $line = substr($this->pendingBuffer, 0, $pos);
            $this->pendingBuffer = substr($this->pendingBuffer, $pos + 1);
            $this->addLine($line);
        }
    }

    /**
     * Append a single already-terminated line, regardless of newline handling.
     */
    public function addLine(string $line): void
    {
        if ($this->closed) {
            return;
        }

        $line = $this->sanitise($line);
        if ($line === '') {
            return;
        }

        $this->lines[] = $line;
        if (count($this->lines) > $this->maxLines) {
            $this->lines = array_slice($this->lines, -$this->maxLines);
        }

        $this->render();
    }

    /**
     * Replace the contents of the panel with the given list of lines.
     *
     * @param list<string> $lines
     */
    public function setLines(array $lines): void
    {
        if ($this->closed) {
            return;
        }

        $trimmed = [];
        foreach ($lines as $line) {
            $clean = $this->sanitise($line);
            if ($clean !== '') {
                $trimmed[] = $clean;
            }
        }

        $this->lines = count($trimmed) > $this->maxLines
            ? array_slice($trimmed, -$this->maxLines)
            : $trimmed;

        $this->render();
    }

    /**
     * Remove the panel from the console, so it leaves no trace.
     */
    public function clear(): void
    {
        if ($this->closed) {
            return;
        }

        $this->flushPending();

        $this->section?->clear();
        $this->lines = [];
        $this->closed = true;
    }

    /**
     * Whether the panel will actually render (false in non-console/test output).
     */
    public function isActive(): bool
    {
        return $this->section !== null && !$this->closed;
    }

    private function render(): void
    {
        if ($this->section === null) {
            return;
        }

        $rendered = [];
        foreach ($this->lines as $line) {
            $rendered[] = $this->indent . '<fg=' . self::COLOR_DIM . '>' . $this->truncate($line) . '</>';
        }

        // Always render at least one blank line so overwrite() has something to
        // replace; otherwise clear() at the end has nothing to remove.
        if ($rendered === []) {
            $rendered[] = '';
        }

        $this->section->overwrite($rendered);
    }

    private function flushPending(): void
    {
        $remainder = rtrim($this->pendingBuffer, "\r\n");
        $this->pendingBuffer = '';
        if ($remainder !== '') {
            $clean = $this->sanitise($remainder);
            if ($clean !== '') {
                $this->lines[] = $clean;
                if (count($this->lines) > $this->maxLines) {
                    $this->lines = array_slice($this->lines, -$this->maxLines);
                }
            }
        }
    }

    private function sanitise(string $line): string
    {
        // Strip ANSI escape sequences so they don't corrupt the panel.
        $line = preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $line) ?? $line;
        $line = str_replace(["\r", "\t"], ['', '  '], $line);
        return trim($line);
    }

    private function truncate(string $line): string
    {
        $max = 100;
        if (mb_strlen($line) > $max) {
            return mb_substr($line, 0, $max - 1) . '…';
        }
        return $line;
    }
}
