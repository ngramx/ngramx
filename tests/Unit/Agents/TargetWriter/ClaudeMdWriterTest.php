<?php

declare(strict_types=1);

namespace Tests\Unit\Agents\TargetWriter;

use Ngramx\Agents\TargetWriter\ClaudeMdWriter;
use PHPUnit\Framework\TestCase;

class ClaudeMdWriterTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = sys_get_temp_dir() . '/ngramx_claude_writer_' . uniqid();
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
        $this->assertFileExists($this->projectDir . '/.claude/rules/ngramx.md');
        $this->assertFileDoesNotExist($this->projectDir . '/CLAUDE.md');

        $content = file_get_contents($this->projectDir . '/.claude/rules/ngramx.md');
        $this->assertIsString($content);
        assert(is_string($content));
        $this->assertStringContainsString('<!-- NGRAMX_CLAUDE_MANAGED_BEGIN -->', $content);
        $this->assertStringContainsString('<!-- NGRAMX_CLAUDE_MANAGED_END -->', $content);
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
        mkdir($this->projectDir . '/.claude/rules', 0755, true);
        file_put_contents($this->projectDir . '/.claude/rules/ngramx.md', "# My Project\n\nUser notes here.");

        $writer = new ClaudeMdWriter();
        $writer->write($this->projectDir, '# Ngramx content');

        $content = file_get_contents($this->projectDir . '/.claude/rules/ngramx.md');
        $this->assertIsString($content);
        assert(is_string($content));
        $this->assertStringContainsString('# My Project', $content);
        $this->assertStringContainsString('User notes here.', $content);
        $this->assertStringContainsString('# Ngramx content', $content);
    }

    public function test_write_replaces_managed_section_on_update(): void
    {
        $writer = new ClaudeMdWriter();
        $writer->write($this->projectDir, '# Original');

        $changed = $writer->write($this->projectDir, '# Updated');
        $this->assertTrue($changed);

        $content = file_get_contents($this->projectDir . '/.claude/rules/ngramx.md');
        $this->assertIsString($content);
        assert(is_string($content));
        $this->assertStringContainsString('# Updated', $content);
        $this->assertStringNotContainsString('# Original', $content);
    }

    public function test_write_deletes_root_claude_md_when_it_is_only_a_generated_block(): void
    {
        file_put_contents(
            $this->projectDir . '/CLAUDE.md',
            "<!-- NGRAMX_CLAUDE_MANAGED_BEGIN -->\n# Old dump\n<!-- NGRAMX_CLAUDE_MANAGED_END -->\n"
        );

        $writer = new ClaudeMdWriter();
        $this->assertTrue($writer->write($this->projectDir, '# Fresh index'));
        $this->assertFileDoesNotExist($this->projectDir . '/CLAUDE.md');
        $this->assertFileExists($this->projectDir . '/.claude/rules/ngramx.md');
    }

    public function test_write_leaves_project_owned_claude_md_untouched(): void
    {
        mkdir($this->projectDir . '/.claude', 0755, true);
        $owned = "<!-- CORTEX START -->\nUse cortex up.\n<!-- CORTEX END -->\n";
        file_put_contents($this->projectDir . '/.claude/CLAUDE.md', $owned);

        $writer = new ClaudeMdWriter();
        $writer->write($this->projectDir, '# Fresh index');

        $this->assertSame($owned, file_get_contents($this->projectDir . '/.claude/CLAUDE.md'));
        $this->assertFileExists($this->projectDir . '/.claude/rules/ngramx.md');
    }

    public function test_write_keeps_project_notes_on_root_claude_md(): void
    {
        file_put_contents(
            $this->projectDir . '/CLAUDE.md',
            "# Keep me\n\n<!-- NGRAMX_CLAUDE_MANAGED_BEGIN -->\n# Old dump\n<!-- NGRAMX_CLAUDE_MANAGED_END -->\n"
        );

        $writer = new ClaudeMdWriter();
        $writer->write($this->projectDir, '# Fresh index');

        $root = file_get_contents($this->projectDir . '/CLAUDE.md');
        $this->assertIsString($root);
        $this->assertStringContainsString('# Keep me', $root);
        $this->assertStringNotContainsString('Old dump', $root);
        $this->assertStringNotContainsString('Fresh index', $root);
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
