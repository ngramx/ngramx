<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Output;

use Ngramx\Output\LiveLogPanel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter as ConsoleFormatter;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

class LiveLogPanelTest extends TestCase
{
    public function test_is_active_returns_false_when_section_is_null(): void
    {
        $panel = new LiveLogPanel(null);
        $this->assertFalse($panel->isActive());
    }

    public function test_is_active_returns_true_for_real_console_section(): void
    {
        ob_start();
        $console = new ConsoleOutput();
        $panel = new LiveLogPanel($console->section());
        $this->assertTrue($panel->isActive());
        ob_end_clean();
    }

    public function test_append_buffer_with_null_section_is_noop(): void
    {
        $panel = new LiveLogPanel(null);
        $panel->appendBuffer("line one\nline two\n");
        $panel->clear();

        $panel->appendBuffer('ignored');

        $this->assertFalse($panel->isActive());
    }

    public function test_append_buffer_splits_chunks_by_newlines(): void
    {
        [$section, $read] = $this->makeSection();
        $panel = new LiveLogPanel($section, 3);

        $panel->appendBuffer("one\ntwo\nthree\nfour\n");
        $output = $this->streamContents($read);

        // With maxLines=3, after four lines we should see the last three.
        $this->assertStringContainsString('two', $output);
        $this->assertStringContainsString('three', $output);
        $this->assertStringContainsString('four', $output);
    }

    public function test_append_buffer_handles_partial_chunks(): void
    {
        [$section, $read] = $this->makeSection();
        $panel = new LiveLogPanel($section, 3);

        $panel->appendBuffer('Booting');
        $panel->appendBuffer(' container');
        $panel->appendBuffer("…\nReady\n");
        $output = $this->streamContents($read);

        $this->assertStringContainsString('Booting container', $output);
        $this->assertStringContainsString('Ready', $output);
    }

    public function test_set_lines_replaces_contents(): void
    {
        [$section, $read] = $this->makeSection();
        $panel = new LiveLogPanel($section, 3);

        $panel->setLines(['alpha', 'beta', 'gamma']);
        $panel->setLines(['delta', 'epsilon']);
        $output = $this->streamContents($read);

        $this->assertStringContainsString('delta', $output);
        $this->assertStringContainsString('epsilon', $output);
    }

    public function test_clear_prevents_further_rendering(): void
    {
        [$section, $read] = $this->makeSection();
        $panel = new LiveLogPanel($section);

        $panel->addLine('before');
        $panel->clear();
        $panel->addLine('after-clear');
        $output = $this->streamContents($read);

        $this->assertStringContainsString('before', $output);
        $this->assertStringNotContainsString('after-clear', $output);
    }

    public function test_ansi_escape_sequences_are_stripped(): void
    {
        [$section, $read] = $this->makeSection();
        $panel = new LiveLogPanel($section);

        $panel->addLine("\x1B[31mred text\x1B[0m");
        $output = $this->streamContents($read);

        // The raw escape sequence from the input must not appear.
        $this->assertStringNotContainsString("\x1B[31m", $output);
        $this->assertStringContainsString('red text', $output);
    }

    /**
     * @return array{0: ConsoleSectionOutput, 1: resource}
     */
    private function makeSection(): array
    {
        $stream = fopen('php://memory', 'r+');
        \assert(is_resource($stream));
        $sections = [];
        $section = new ConsoleSectionOutput(
            $stream,
            $sections,
            OutputInterface::VERBOSITY_NORMAL,
            false,
            new ConsoleFormatter(),
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
}
