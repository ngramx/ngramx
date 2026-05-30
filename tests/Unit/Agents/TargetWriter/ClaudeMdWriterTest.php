<?php

declare(strict_types=1);

namespace Tests\Unit\Agents\TargetWriter;

use Cortex\Agents\TargetWriter\ClaudeMdWriter;
use PHPUnit\Framework\TestCase;

class ClaudeMdWriterTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = sys_get_temp_dir() . '/cortex_claude_writer_' . uniqid();
        mkdir($this->projectDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveRemove($this->projectDir);
    }

    public function test_write_creates_claude_md_with_markers(): void
    {
        $writer = new ClaudeMdWriter();
        $changed = $writer->write($this->projectDir, '# Test content');

        $this->assertTrue($changed);
        $this->assertFileExists($this->projectDir . '/CLAUDE.md');

        $content = file_get_contents($this->projectDir . '/CLAUDE.md');
        $this->assertIsString($content);
        assert(is_string($content));
        $this->assertStringContainsString('<!-- CORTEX_CLAUDE_MANAGED_BEGIN -->', $content);
        $this->assertStringContainsString('<!-- CORTEX_CLAUDE_MANAGED_END -->', $content);
        $this->assertStringContainsString('# Test content', $content);
    }

    public function test_write_is_idempotent(): void
    {
        $writer = new ClaudeMdWriter();
        $writer->write($this->projectDir, '# Test');

        $second = $writer->write($this->projectDir, '# Test');
        $this->assertFalse($second);
    }

    public function test_write_preserves_existing_content(): void
    {
        file_put_contents($this->projectDir . '/CLAUDE.md', "# My Project\n\nUser notes here.");

        $writer = new ClaudeMdWriter();
        $writer->write($this->projectDir, '# Cortex content');

        $content = file_get_contents($this->projectDir . '/CLAUDE.md');
        $this->assertIsString($content);
        assert(is_string($content));
        $this->assertStringContainsString('# My Project', $content);
        $this->assertStringContainsString('User notes here.', $content);
        $this->assertStringContainsString('# Cortex content', $content);
    }

    public function test_write_replaces_managed_section_on_update(): void
    {
        $writer = new ClaudeMdWriter();
        $writer->write($this->projectDir, '# Original');

        $changed = $writer->write($this->projectDir, '# Updated');
        $this->assertTrue($changed);

        $content = file_get_contents($this->projectDir . '/CLAUDE.md');
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
