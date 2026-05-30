<?php

declare(strict_types=1);

namespace Tests\Unit\Agents\TargetWriter;

use Ngramx\Agents\TargetWriter\CursorRulesWriter;
use PHPUnit\Framework\TestCase;

class CursorRulesWriterTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = sys_get_temp_dir() . '/ngramx_cursor_writer_' . uniqid();
        mkdir($this->projectDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveRemove($this->projectDir);
    }

    public function test_write_creates_mdc_file_with_frontmatter(): void
    {
        $writer = new CursorRulesWriter();
        $changed = $writer->write($this->projectDir, '# Test content');

        $this->assertTrue($changed);
        $this->assertFileExists($this->projectDir . '/.cursor/rules/ngramx.mdc');

        $content = file_get_contents($this->projectDir . '/.cursor/rules/ngramx.mdc');
        $this->assertIsString($content);
        assert(is_string($content));
        $this->assertStringContainsString('alwaysApply: true', $content);
        $this->assertStringContainsString('# Test content', $content);
    }

    public function test_write_is_idempotent(): void
    {
        $writer = new CursorRulesWriter();
        $writer->write($this->projectDir, '# Test');

        $second = $writer->write($this->projectDir, '# Test');
        $this->assertFalse($second);
    }

    public function test_write_updates_when_content_changes(): void
    {
        $writer = new CursorRulesWriter();
        $writer->write($this->projectDir, '# Original');

        $changed = $writer->write($this->projectDir, '# Updated');
        $this->assertTrue($changed);

        $content = file_get_contents($this->projectDir . '/.cursor/rules/ngramx.mdc');
        $this->assertIsString($content);
        assert(is_string($content));
        $this->assertStringContainsString('# Updated', $content);
        $this->assertStringNotContainsString('# Original', $content);
    }

    public function test_write_creates_directory_structure(): void
    {
        $writer = new CursorRulesWriter();
        $writer->write($this->projectDir, '# Content');

        $this->assertDirectoryExists($this->projectDir . '/.cursor/rules');
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
