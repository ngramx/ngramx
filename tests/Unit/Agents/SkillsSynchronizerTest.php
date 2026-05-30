<?php

declare(strict_types=1);

namespace Tests\Unit\Agents;

use Ngramx\Agents\SkillsSynchronizer;
use PHPUnit\Framework\TestCase;

class SkillsSynchronizerTest extends TestCase
{
    private string $projectDir;
    private string $templatesRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = sys_get_temp_dir() . '/ngramx_skills_test_' . uniqid();
        $this->templatesRoot = sys_get_temp_dir() . '/ngramx_skills_templates_' . uniqid();
        mkdir($this->projectDir, 0755, true);
        mkdir($this->templatesRoot . '/skills/test-skill', 0755, true);
        file_put_contents(
            $this->templatesRoot . '/skills/test-skill/SKILL.md',
            "---\nname: test-skill\ndescription: A test skill\n---\n\n# Test Skill\n\nInstructions here."
        );
    }

    protected function tearDown(): void
    {
        $this->recursiveRemove($this->projectDir);
        $this->recursiveRemove($this->templatesRoot);
    }

    public function test_sync_copies_skill_to_cursor_target(): void
    {
        $sync = new SkillsSynchronizer($this->templatesRoot);
        $changed = $sync->sync($this->projectDir, ['cursor']);

        $this->assertTrue($changed);
        $this->assertFileExists($this->projectDir . '/.cursor/skills/test-skill/SKILL.md');

        $content = file_get_contents($this->projectDir . '/.cursor/skills/test-skill/SKILL.md');
        $this->assertIsString($content);
        assert(is_string($content));
        $this->assertStringContainsString('test-skill', $content);
    }

    public function test_sync_copies_skill_to_claude_target(): void
    {
        $sync = new SkillsSynchronizer($this->templatesRoot);
        $changed = $sync->sync($this->projectDir, ['claude']);

        $this->assertTrue($changed);
        $this->assertFileExists($this->projectDir . '/.claude/skills/test-skill/SKILL.md');
    }

    public function test_sync_copies_to_multiple_targets(): void
    {
        $sync = new SkillsSynchronizer($this->templatesRoot);
        $changed = $sync->sync($this->projectDir, ['cursor', 'claude']);

        $this->assertTrue($changed);
        $this->assertFileExists($this->projectDir . '/.cursor/skills/test-skill/SKILL.md');
        $this->assertFileExists($this->projectDir . '/.claude/skills/test-skill/SKILL.md');
    }

    public function test_sync_is_idempotent(): void
    {
        $sync = new SkillsSynchronizer($this->templatesRoot);

        $first = $sync->sync($this->projectDir, ['cursor']);
        $this->assertTrue($first);

        $second = $sync->sync($this->projectDir, ['cursor']);
        $this->assertFalse($second);
    }

    public function test_sync_updates_when_content_changes(): void
    {
        $sync = new SkillsSynchronizer($this->templatesRoot);
        $sync->sync($this->projectDir, ['cursor']);

        // Change the source
        file_put_contents(
            $this->templatesRoot . '/skills/test-skill/SKILL.md',
            "---\nname: test-skill\ndescription: Updated\n---\n\n# Updated"
        );

        $changed = $sync->sync($this->projectDir, ['cursor']);
        $this->assertTrue($changed);

        $content = file_get_contents($this->projectDir . '/.cursor/skills/test-skill/SKILL.md');
        $this->assertIsString($content);
        assert(is_string($content));
        $this->assertStringContainsString('Updated', $content);
    }

    public function test_sync_returns_false_when_no_skills_directory(): void
    {
        $emptyRoot = sys_get_temp_dir() . '/ngramx_empty_' . uniqid();
        mkdir($emptyRoot, 0755, true);

        try {
            $sync = new SkillsSynchronizer($emptyRoot);
            $this->assertFalse($sync->sync($this->projectDir, ['cursor']));
        } finally {
            @rmdir($emptyRoot);
        }
    }

    public function test_sync_ignores_unknown_targets(): void
    {
        $sync = new SkillsSynchronizer($this->templatesRoot);
        $changed = $sync->sync($this->projectDir, ['unknown_target']);

        $this->assertFalse($changed);
    }

    public function test_sync_discovers_multiple_skills(): void
    {
        mkdir($this->templatesRoot . '/skills/second-skill', 0755, true);
        file_put_contents(
            $this->templatesRoot . '/skills/second-skill/SKILL.md',
            "---\nname: second-skill\ndescription: Second\n---\n\n# Second"
        );

        $sync = new SkillsSynchronizer($this->templatesRoot);
        $sync->sync($this->projectDir, ['cursor']);

        $this->assertFileExists($this->projectDir . '/.cursor/skills/test-skill/SKILL.md');
        $this->assertFileExists($this->projectDir . '/.cursor/skills/second-skill/SKILL.md');
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
