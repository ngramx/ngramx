<?php

declare(strict_types=1);

namespace Ngramx\Output;

use Ngramx\Config\Schema\CommandDefinition;
use Symfony\Component\Console\Formatter\OutputFormatter as ConsoleFormatter;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

class OutputFormatter
{
    // Gigabyte Brand Colors
    public const COLOR_TEAL = '#2ED9C3';    // Pantone 3255C
    public const COLOR_PURPLE = '#7D55C7';  // Pantone 2665C
    public const COLOR_SMOKE = '#D2DCE5';   // Pantone 5455C

    private const SERVICE_NAME_PAD = 2;

    /**
     * Sticky indentation (in spaces) shared by all messages. Methods that have
     * a semantic level (info, command, commandOutput, section, …) set this, and
     * contextual messages (warning, error, success) inherit it unless an
     * explicit indent is passed. The effect: a warning/error appears at the same
     * indentation as the surrounding output instead of jumping back to column 0.
     */
    private int $indent = 0;

    public function __construct(
        private readonly OutputInterface $output,
    ) {
    }

    public function getOutput(): OutputInterface
    {
        return $this->output;
    }

    /**
     * Explicitly set the sticky indentation (in spaces) for subsequent messages
     * that don't pin their own indent.
     */
    public function setIndent(int $spaces): void
    {
        $this->indent = max(0, $spaces);
    }

    /**
     * Reset the sticky indentation back to column 0. Useful at the start of a
     * top-level block (e.g. a fatal error) that should not inherit indentation.
     */
    public function resetIndent(): void
    {
        $this->indent = 0;
    }

    /**
     * Write a single styled line. When $indent is given it becomes the new
     * sticky indent; when null, the current sticky indent is reused.
     */
    private function writeAt(?int $indent, string $styled): void
    {
        if ($indent !== null) {
            $this->indent = max(0, $indent);
        }

        $this->output->writeln(str_repeat(' ', $this->indent) . $styled);
    }

    /**
     * Create a rewritable console section (for live-updating output).
     * Falls back to null if the output doesn't support sections.
     */
    public function createSection(): ?ConsoleSectionOutput
    {
        if ($this->output instanceof ConsoleOutput) {
            return $this->output->section();
        }

        return null;
    }

    /**
     * Render the live container status block into a console section.
     *
     * Each service is rendered as a header row "<name> <status>" followed by
     * up to 3 recent log lines in dim grey whenever the service has not yet
     * become healthy. Once healthy, the log block for that service disappears
     * on the next render, leaving only the final status.
     *
     * The $services entry may optionally provide `logLines` (a list of strings)
     * in addition to, or instead of, the legacy single `log` string.
     *
     * @param ConsoleSectionOutput $section
     * @param array<string, array{status: string, elapsed: float|null, log?: string|null, logLines?: list<string>}> $services
     */
    public function renderServiceStatus(ConsoleSectionOutput $section, array $services): void
    {
        $maxNameLen = 0;
        foreach ($services as $name => $_) {
            $maxNameLen = max($maxNameLen, mb_strlen($name));
        }
        $nameWidth = $maxNameLen + self::SERVICE_NAME_PAD;
        $logIndent = '  ' . str_repeat(' ', $nameWidth) . '  ';

        $lines = [];
        foreach ($services as $name => $info) {
            $paddedName = str_pad($name, $nameWidth);
            $statusText = $this->formatStatus($info['status'], $info['elapsed']);
            $lines[] = '  <fg=' . self::COLOR_SMOKE . ">{$paddedName}</>{$statusText}";

            if ($this->isHealthyStatus($info['status'])) {
                continue;
            }

            $logLines = $this->extractLogLines($info);
            foreach ($logLines as $logLine) {
                $truncated = mb_strlen($logLine) > 80
                    ? mb_substr($logLine, 0, 77) . '...'
                    : $logLine;
                $lines[] = $logIndent . '<fg=' . self::COLOR_SMOKE . ">{$truncated}</>";
            }
        }

        $section->overwrite($lines);
    }

    /**
     * Remove the live service status panel entirely from the console.
     */
    public function clearServiceStatus(ConsoleSectionOutput $section): void
    {
        $section->clear();
    }

    public function section(string $title): void
    {
        $this->output->writeln('');
        $this->writeAt(0, '<fg=' . self::COLOR_TEAL . ">▸ $title</>");
    }

    public function command(CommandDefinition $cmd): void
    {
        $this->writeAt(2, '<fg=' . self::COLOR_SMOKE . ">{$cmd->description}</>");
    }

    public function success(string $message, ?int $indent = null): void
    {
        $this->writeAt($indent, '<fg=' . self::COLOR_PURPLE . ">$message</>");
    }

    /**
     * Render an error, prefixed with a ✗ icon and followed by a blank line so it
     * stands out from surrounding output. Multi-line messages (e.g. captured git
     * output) keep their continuation lines indented to align under the first line.
     */
    public function error(string $message, ?int $indent = null): void
    {
        if ($indent !== null) {
            $this->indent = max(0, $indent);
        }
        $pad = str_repeat(' ', $this->indent);

        $lines = explode("\n", $message);
        // explode() always yields at least one element, so this is never null.
        $first = array_shift($lines);

        $this->output->writeln("{$pad}<fg=red>✗ {$first}</>");

        foreach ($lines as $line) {
            $this->output->writeln("{$pad}<fg=red>  {$line}</>");
        }

        $this->output->writeln('');
    }

    /**
     * Escape text that may contain characters the console formatter would
     * otherwise interpret as style tags (e.g. arbitrary git output).
     */
    public static function escape(string $text): string
    {
        return ConsoleFormatter::escape($text);
    }

    public function warning(string $message, ?int $indent = null): void
    {
        $this->writeAt($indent, "<fg=yellow>$message</>");
    }

    public function info(string $message): void
    {
        $this->writeAt(2, '<fg=' . self::COLOR_SMOKE . ">$message</>");
    }

    public function commandOutput(string $output): void
    {
        $this->indent = 4;
        $pad = str_repeat(' ', $this->indent);
        $lines = explode("\n", trim($output));
        foreach ($lines as $line) {
            $this->output->writeln("{$pad}<fg=gray>$line</>");
        }
    }

    public function welcome(string $title = 'Starting Development Environment'): void
    {
        $this->indent = 0;
        $this->output->writeln('');
        $this->output->writeln('<fg=' . self::COLOR_PURPLE . '>──────────────────────────────────────────────────</>');
        $this->output->writeln('<fg=' . self::COLOR_PURPLE . '> ' . $title . '</>');
        $this->output->writeln('<fg=' . self::COLOR_PURPLE . '>──────────────────────────────────────────────────</>');
        $this->output->writeln('');
    }

    public function completionSummary(float $totalTime, ?string $appUrl = null): void
    {
        $this->indent = 0;
        $this->output->writeln('');
        $this->output->writeln(sprintf('<fg=' . self::COLOR_PURPLE . '>Environment ready! (%.1fs)</>', $totalTime));
        if ($appUrl !== null) {
            $this->output->writeln(sprintf('<fg=' . self::COLOR_TEAL . '>➜ Application: %s</>', $appUrl));
        }
        $this->output->writeln('');
    }

    public function url(string $label, string $url): void
    {
        $this->writeAt(null, sprintf('<fg=' . self::COLOR_TEAL . '>➜ %s:</> %s', $label, $url));
    }

    /**
     * Extract log lines from a service status entry, supporting both the
     * legacy single-line `log` key and the newer multi-line `logLines` key.
     *
     * @param array{status: string, elapsed: float|null, log?: string|null, logLines?: list<string>} $info
     * @return list<string>
     */
    private function extractLogLines(array $info): array
    {
        if (isset($info['logLines']) && $info['logLines'] !== []) {
            return array_values(array_filter($info['logLines'], static fn (string $l) => trim($l) !== ''));
        }

        if (isset($info['log']) && trim($info['log']) !== '') {
            return [$info['log']];
        }

        return [];
    }

    private function formatStatus(string $status, ?float $elapsed): string
    {
        $color = match ($status) {
            'unhealthy', 'exited', 'restarting' => 'red',
            default => self::COLOR_PURPLE,
        };

        $label = $status;
        if ($this->isHealthyStatus($status) && $elapsed !== null) {
            $label = sprintf('%s (%.1fs)', $status, $elapsed);
        }

        return "<fg={$color}>{$label}</>";
    }

    private function isHealthyStatus(string $status): bool
    {
        return $status === 'healthy' || $status === 'running' || $status === 'ready';
    }
}
