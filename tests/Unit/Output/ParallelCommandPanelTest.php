<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Output;

use Ngramx\Output\ParallelCommandPanel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter as ConsoleFormatter;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

class ParallelCommandPanelTest extends TestCase
{
    public function test_is_active_returns_false_when_section_is_null(): void
    {
        $panel = new ParallelCommandPanel(null, ['a', 'b']);
        $this->assertFalse($panel->isActive());
    }

    public function test_is_active_reflects_section_and_close_state(): void
    {
        [$section] = $this->makeSection();
        $panel = new ParallelCommandPanel($section, ['a']);
        $this->assertTrue($panel->isActive());

        $panel->close();
        $this->assertFalse($panel->isActive());
    }

    public function test_update_line_renders_latest_line_per_label(): void
    {
        [$section, $stream] = $this->makeSection();
        $panel = new ParallelCommandPanel($section, ['alpha', 'beta']);

        $panel->updateLine('alpha', 'line one');
        $panel->updateLine('beta', 'other line');
        $panel->updateLine('alpha', 'line two');

        $output = $this->streamContents($stream);

        $this->assertStringContainsString('alpha', $output);
        $this->assertStringContainsString('beta', $output);
        $this->assertStringContainsString('line two', $output);
        $this->assertStringContainsString('other line', $output);
    }

    public function test_finished_slot_is_removed_on_next_render(): void
    {
        [$section] = $this->makeSection();
        $panel = new ParallelCommandPanel($section, ['alpha', 'beta']);

        $panel->updateLine('alpha', 'first');
        $panel->updateLine('beta', 'second');
        $panel->markFinished('alpha');
        $panel->updateLine('beta', 'third');

        // Check the current logical contents of the section, not the full
        // stream history — overwrite() resets the section each render.
        $content = $section->getContent();

        $this->assertStringNotContainsString('alpha', $content);
        $this->assertStringContainsString('beta', $content);
        $this->assertStringContainsString('third', $content);
    }

    public function test_close_clears_panel_contents(): void
    {
        [$section] = $this->makeSection();
        $panel = new ParallelCommandPanel($section, ['alpha']);
        $panel->updateLine('alpha', 'hello');
        $panel->close();

        $this->assertSame('', $section->getContent());
    }

    public function test_ansi_escape_sequences_are_stripped(): void
    {
        [$section, $stream] = $this->makeSection();
        $panel = new ParallelCommandPanel($section, ['alpha']);

        $panel->updateLine('alpha', "\x1B[31mred\x1B[0m message");
        $output = $this->streamContents($stream);

        $this->assertStringNotContainsString("\x1B[31m", $output);
        $this->assertStringContainsString('red message', $output);
    }

    public function test_labels_are_padded_so_colons_align(): void
    {
        [$section] = $this->makeSection();
        $panel = new ParallelCommandPanel($section, ['a', 'longer']);

        $panel->updateLine('a', 'x');
        $panel->updateLine('longer', 'y');

        $content = $this->stripAnsi($section->getContent());

        // Both rendered lines should use the same label column width (6 chars for "longer").
        $this->assertMatchesRegularExpression('/a\s{5}: /', $content);
        $this->assertMatchesRegularExpression('/longer: /', $content);
    }

    public function test_fallback_output_prints_prefixed_lines_when_no_section(): void
    {
        $stream = fopen('php://memory', 'r+');
        \assert(is_resource($stream));
        $fallback = new StreamOutput($stream);

        $panel = new ParallelCommandPanel(null, ['alpha'], $fallback);
        $panel->updateLine('alpha', 'hello');

        rewind($stream);
        $contents = stream_get_contents($stream);
        $this->assertIsString($contents);
        $this->assertStringContainsString('alpha', $contents);
        $this->assertStringContainsString('hello', $contents);
    }

    /**
     * @return array{0: ConsoleSectionOutput, 1: resource}
     */
    private function makeSection(bool $decorated = true): array
    {
        $stream = fopen('php://memory', 'r+');
        \assert(is_resource($stream));
        $sections = [];
        $section = new ConsoleSectionOutput(
            $stream,
            $sections,
            OutputInterface::VERBOSITY_NORMAL,
            $decorated,
            new ConsoleFormatter($decorated),
        );
        return [$section, $stream];
    }

    /** @param resource $stream */
    private function streamContents($stream): string
    {
        rewind($stream);
        $contents = stream_get_contents($stream);
        return $contents === false ? '' : $contents;
    }

    private function stripAnsi(string $value): string
    {
        return preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $value) ?? $value;
    }
}
