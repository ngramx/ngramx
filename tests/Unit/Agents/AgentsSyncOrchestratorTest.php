<?php

declare(strict_types=1);

namespace Tests\Unit\Agents;

use Ngramx\Agents\AgentsSyncOrchestrator;
use Ngramx\Config\Schema\AgentsConfig;
use PHPUnit\Framework\TestCase;

class AgentsSyncOrchestratorTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = sys_get_temp_dir() . '/ngramx_orchestrator_test_' . uniqid();
        mkdir($this->projectDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveRemove($this->projectDir);
    }

    public function test_sync_with_defaults_writes_agent_folders_and_leaves_agents_md_alone(): void
    {
        $orchestrator = new AgentsSyncOrchestrator();
        $result = $orchestrator->syncWithDefaults($this->projectDir);

        $this->assertContains('cursor_rules', $result['targets_changed']);
        $this->assertContains('claude_md', $result['targets_changed']);
        $this->assertFileDoesNotExist($this->projectDir . '/AGENTS.md');
        $this->assertFileDoesNotExist($this->projectDir . '/CLAUDE.md');
        $this->assertFileExists($this->projectDir . '/.cursor/rules/ngramx.mdc');
        $this->assertFileExists($this->projectDir . '/.claude/rules/ngramx.md');
        $this->assertFileExists($this->projectDir . '/.gitignore');

        $rules = file_get_contents($this->projectDir . '/.cursor/rules/ngramx.mdc');
        $this->assertIsString($rules);
        $this->assertStringContainsString('ngramx-start-ticket', $rules);
        $this->assertStringNotContainsString('HasUuids', $rules);
        $this->assertTrue($result['gitignore_changed']);
        $this->assertTrue($result['skills_changed']);
    }

    public function test_sync_with_all_targets_does_not_write_root_agent_files(): void
    {
        $config = new AgentsConfig(
            targets: ['agents_md', 'cursor_rules', 'claude_md', 'copilot_instructions'],
            skills: ['cursor', 'claude'],
        );

        $orchestrator = new AgentsSyncOrchestrator();
        $result = $orchestrator->sync($this->projectDir, $config);

        $this->assertFileDoesNotExist($this->projectDir . '/AGENTS.md');
        $this->assertFileDoesNotExist($this->projectDir . '/CLAUDE.md');
        $this->assertFileExists($this->projectDir . '/.cursor/rules/ngramx.mdc');
        $this->assertFileExists($this->projectDir . '/.claude/rules/ngramx.md');
        $this->assertFileExists($this->projectDir . '/.github/copilot-instructions.md');
        $this->assertTrue($result['skills_changed']);
    }

    public function test_sync_respects_configured_targets(): void
    {
        $config = new AgentsConfig(
            targets: ['agents_md'],
            skills: [],
        );

        $orchestrator = new AgentsSyncOrchestrator();
        $orchestrator->sync($this->projectDir, $config);

        $this->assertFileDoesNotExist($this->projectDir . '/AGENTS.md');
        $this->assertFileDoesNotExist($this->projectDir . '/.cursor/rules/ngramx.mdc');
        $this->assertFileDoesNotExist($this->projectDir . '/.claude/rules/ngramx.md');
        $this->assertFileDoesNotExist($this->projectDir . '/CLAUDE.md');
    }

    public function test_sync_retires_previously_managed_agents_md(): void
    {
        file_put_contents(
            $this->projectDir . '/AGENTS.md',
            "# Project notes\n\nKeep this.\n\n<!-- NGRAMX_AGENTS_MANAGED_BEGIN -->\n\nHuge dump\n<!-- NGRAMX_AGENTS_MANAGED_END -->\n"
        );

        $orchestrator = new AgentsSyncOrchestrator();
        $result = $orchestrator->syncWithDefaults($this->projectDir);

        $this->assertContains('agents_md_retired', $result['targets_changed']);
        $content = file_get_contents($this->projectDir . '/AGENTS.md');
        $this->assertIsString($content);
        $this->assertStringContainsString('Keep this.', $content);
        $this->assertStringNotContainsString('Huge dump', $content);
        $this->assertStringNotContainsString('NGRAMX_AGENTS_MANAGED', $content);
    }

    public function test_sync_is_idempotent(): void
    {
        $orchestrator = new AgentsSyncOrchestrator();

        $first = $orchestrator->syncWithDefaults($this->projectDir);
        $this->assertNotEmpty($first['targets_changed']);

        $second = $orchestrator->syncWithDefaults($this->projectDir);
        $this->assertEmpty($second['targets_changed']);
        $this->assertFalse($second['skills_changed']);
        $this->assertFalse($second['gitignore_changed']);
    }

    public function test_sync_creates_prefixed_skills_in_cursor_directory(): void
    {
        $config = new AgentsConfig(
            targets: ['cursor_rules'],
            skills: ['cursor'],
        );

        $orchestrator = new AgentsSyncOrchestrator();
        $result = $orchestrator->sync($this->projectDir, $config);

        $this->assertTrue($result['skills_changed']);
        $this->assertDirectoryExists($this->projectDir . '/.cursor/skills');
        $this->assertFileExists($this->projectDir . '/.cursor/skills/ngramx-create-pr/SKILL.md');
        $this->assertFileExists($this->projectDir . '/.cursor/skills/ngramx-start-ticket/SKILL.md');
        $this->assertFileExists($this->projectDir . '/.cursor/skills/ngramx-create-linear-tickets/SKILL.md');
        $this->assertFileDoesNotExist($this->projectDir . '/.cursor/skills/start-ticket/SKILL.md');
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
