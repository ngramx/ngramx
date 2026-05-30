<?php

declare(strict_types=1);

namespace Tests\Unit\Agents\TargetWriter;

use Ngramx\Agents\TargetWriter\CopilotInstructionsWriter;
use PHPUnit\Framework\TestCase;

class CopilotInstructionsWriterTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = sys_get_temp_dir() . '/ngramx_copilot_writer_' . uniqid();
        mkdir($this->projectDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveRemove($this->projectDir);
    }

    public function test_write_creates_copilot_instructions_with_markers(): void
    {
        $writer = new CopilotInstructionsWriter();
        $changed = $writer->write($this->projectDir, '# Test content');

        $this->assertTrue($changed);
        $this->assertFileExists($this->projectDir . '/.github/copilot-instructions.md');

        $content = file_get_contents($this->projectDir . '/.github/copilot-instructions.md');
        $this->assertIsString($content);
        assert(is_string($content));
        $this->assertStringContainsString('<!-- NGRAMX_COPILOT_MANAGED_BEGIN -->', $content);
        $this->assertStringContainsString('<!-- NGRAMX_COPILOT_MANAGED_END -->', $content);
        $this->assertStringContainsString('# Test content', $content);
    }

    public function test_write_creates_github_directory(): void
    {
        $writer = new CopilotInstructionsWriter();
        $writer->write($this->projectDir, '# Content');

        $this->assertDirectoryExists($this->projectDir . '/.github');
    }

    public function test_write_is_idempotent(): void
    {
        $writer = new CopilotInstructionsWriter();
        $writer->write($this->projectDir, '# Test');

        $second = $writer->write($this->projectDir, '# Test');
        $this->assertFalse($second);
    }

    public function test_write_replaces_managed_section(): void
    {
        $writer = new CopilotInstructionsWriter();
        $writer->write($this->projectDir, '# Original');

        $changed = $writer->write($this->projectDir, '# Updated');
        $this->assertTrue($changed);

        $content = file_get_contents($this->projectDir . '/.github/copilot-instructions.md');
        $this->assertIsString($content);
        assert(is_string($content));
        $this->assertStringContainsString('# Updated', $content);
        $this->assertStringNotContainsString('# Original', $content);
    }

    private function recursiveRemove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->recursiveRemove($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
