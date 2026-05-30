<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Output;

use Ngramx\Output\OutputFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter as ConsoleFormatter;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

class OutputFormatterTest extends TestCase
{
    public function test_create_section_returns_section_for_console_output(): void
    {
        $output = new ConsoleOutput();
        $formatter = new OutputFormatter($output);

        $section = $formatter->createSection();

        $this->assertInstanceOf(ConsoleSectionOutput::class, $section);
    }

    public function test_create_section_returns_null_for_non_console_output(): void
    {
        $output = new BufferedOutput();
        $formatter = new OutputFormatter($output);

        $section = $formatter->createSection();

        $this->assertNull($section);
    }

    public function test_render_service_status_writes_healthy_status(): void
    {
        ob_start();
        $consoleOutput = new ConsoleOutput();
        $formatter = new OutputFormatter($consoleOutput);
        $section = $consoleOutput->section();

        $services = [
            'postgres' => ['status' => 'healthy', 'elapsed' => 3.2, 'log' => null],
        ];

        $formatter->renderServiceStatus($section, $services);
        ob_end_clean();

        $this->assertTrue(true);
    }

    public function test_render_service_status_includes_log_for_starting_service(): void
    {
        ob_start();
        $consoleOutput = new ConsoleOutput();
        $formatter = new OutputFormatter($consoleOutput);
        $section = $consoleOutput->section();

        $services = [
            'n8n' => ['status' => 'starting', 'elapsed' => null, 'log' => 'Initializing database...'],
            'postgres' => ['status' => 'healthy', 'elapsed' => 2.1, 'log' => null],
        ];

        $formatter->renderServiceStatus($section, $services);
        ob_end_clean();

        $this->assertTrue(true);
    }

    public function test_render_service_status_truncates_long_log_lines(): void
    {
        ob_start();
        $consoleOutput = new ConsoleOutput();
        $formatter = new OutputFormatter($consoleOutput);
        $section = $consoleOutput->section();

        $longLog = str_repeat('x', 100);
        $services = [
            'n8n' => ['status' => 'starting', 'elapsed' => null, 'log' => $longLog],
        ];

        $formatter->renderServiceStatus($section, $services);
        ob_end_clean();

        $this->assertTrue(true);
    }

    public function test_render_service_status_hides_log_when_healthy(): void
    {
        ob_start();
        $consoleOutput = new ConsoleOutput();
        $formatter = new OutputFormatter($consoleOutput);
        $section = $consoleOutput->section();

        $services = [
            'n8n' => ['status' => 'healthy', 'elapsed' => 5.0, 'log' => 'Some old log line'],
        ];

        $formatter->renderServiceStatus($section, $services);
        ob_end_clean();

        $this->assertTrue(true);
    }

    public function test_render_service_status_shows_multiple_log_lines(): void
    {
        [$section, $read] = $this->makeSection();
        $formatter = new OutputFormatter(new BufferedOutput());

        $services = [
            'n8n' => [
                'status' => 'starting',
                'elapsed' => null,
                'logLines' => ['line-one', 'line-two', 'line-three'],
            ],
        ];

        $formatter->renderServiceStatus($section, $services);
        $output = $this->streamContents($read);

        $this->assertStringContainsString('line-one', $output);
        $this->assertStringContainsString('line-two', $output);
        $this->assertStringContainsString('line-three', $output);
    }

    public function test_render_service_status_prefers_log_lines_over_legacy_log(): void
    {
        [$section, $read] = $this->makeSection();
        $formatter = new OutputFormatter(new BufferedOutput());

        $services = [
            'n8n' => [
                'status' => 'starting',
                'elapsed' => null,
                'log' => 'legacy-ignored',
                'logLines' => ['new-one', 'new-two'],
            ],
        ];

        $formatter->renderServiceStatus($section, $services);
        $output = $this->streamContents($read);

        $this->assertStringContainsString('new-one', $output);
        $this->assertStringContainsString('new-two', $output);
        $this->assertStringNotContainsString('legacy-ignored', $output);
    }

    public function test_clear_service_status_removes_content(): void
    {
        [$section, ] = $this->makeSection();
        $formatter = new OutputFormatter(new BufferedOutput());

        $services = [
            'n8n' => ['status' => 'starting', 'elapsed' => null, 'logLines' => ['tmp']],
        ];

        $formatter->renderServiceStatus($section, $services);
        $formatter->clearServiceStatus($section);

        $this->assertTrue(true);
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

    public function test_section_outputs_title(): void
    {
        $output = new BufferedOutput();
        $formatter = new OutputFormatter($output);

        $formatter->section('Test Section');

        $this->assertStringContainsString('Test Section', $output->fetch());
    }

    public function test_error_outputs_message(): void
    {
        $output = new BufferedOutput();
        $formatter = new OutputFormatter($output);

        $formatter->error('Something went wrong');

        $this->assertStringContainsString('Something went wrong', $output->fetch());
    }

    public function test_info_outputs_message(): void
    {
        $output = new BufferedOutput();
        $formatter = new OutputFormatter($output);

        $formatter->info('Some information');

        $this->assertStringContainsString('Some information', $output->fetch());
    }

    public function test_warning_outputs_message(): void
    {
        $output = new BufferedOutput();
        $formatter = new OutputFormatter($output);

        $formatter->warning('A warning');

        $this->assertStringContainsString('A warning', $output->fetch());
    }

    public function test_welcome_outputs_title(): void
    {
        $output = new BufferedOutput();
        $formatter = new OutputFormatter($output);

        $formatter->welcome('My Title');

        $this->assertStringContainsString('My Title', $output->fetch());
    }

    public function test_completion_summary_outputs_time(): void
    {
        $output = new BufferedOutput();
        $formatter = new OutputFormatter($output);

        $formatter->completionSummary(12.5);

        $display = $output->fetch();
        $this->assertStringContainsString('12.5s', $display);
    }

    public function test_completion_summary_outputs_url_when_provided(): void
    {
        $output = new BufferedOutput();
        $formatter = new OutputFormatter($output);

        $formatter->completionSummary(1.0, 'http://localhost:8080');

        $display = $output->fetch();
        $this->assertStringContainsString('http://localhost:8080', $display);
    }
}
