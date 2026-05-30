<?php

declare(strict_types=1);

namespace Tests\Unit\Agents;

use Cortex\Agents\AgentsSyncOrchestrator;
use Cortex\Config\Schema\AgentsConfig;
use PHPUnit\Framework\TestCase;

class AgentsSyncOrchestratorTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = sys_get_temp_dir() . '/cortex_orchestrator_test_' . uniqid();
        mkdir($this->projectDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveRemove($this->projectDir);
    }

    public function test_sync_with_defaults_creates_agents_md_and_cursor_rules(): void
    {
        $orchestrator = new AgentsSyncOrchestrator();
        $result = $orchestrator->syncWithDefaults($this->projectDir);

        $this->assertContains('agents_md', $result['targets_changed']);
        $this->assertFileExists($this->projectDir . '/AGENTS.md');
        $this->assertFileExists($this->projectDir . '/.cursor/rules/cortex.mdc');
    }

    public function test_sync_with_all_targets(): void
    {
        $config = new AgentsConfig(
            targets: ['agents_md', 'cursor_rules', 'claude_md', 'copilot_instructions'],
            skills: ['cursor', 'claude'],
        );

        $orchestrator = new AgentsSyncOrchestrator();
        $result = $orchestrator->sync($this->projectDir, $config);

        $this->assertFileExists($this->projectDir . '/AGENTS.md');
        $this->assertFileExists($this->projectDir . '/.cursor/rules/cortex.mdc');
        $this->assertFileExists($this->projectDir . '/CLAUDE.md');
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

        $this->assertFileExists($this->projectDir . '/AGENTS.md');
        $this->assertFileDoesNotExist($this->projectDir . '/.cursor/rules/cortex.mdc');
        $this->assertFileDoesNotExist($this->projectDir . '/CLAUDE.md');
    }

    public function test_sync_is_idempotent(): void
    {
        $orchestrator = new AgentsSyncOrchestrator();

        $first = $orchestrator->syncWithDefaults($this->projectDir);
        $this->assertNotEmpty($first['targets_changed']);

        $second = $orchestrator->syncWithDefaults($this->projectDir);
        $this->assertEmpty($second['targets_changed']);
        $this->assertFalse($second['skills_changed']);
    }

    public function test_sync_creates_skills_in_cursor_directory(): void
    {
        $config = new AgentsConfig(
            targets: ['agents_md'],
            skills: ['cursor'],
        );

        $orchestrator = new AgentsSyncOrchestrator();
        $result = $orchestrator->sync($this->projectDir, $config);

        $this->assertTrue($result['skills_changed']);
        $this->assertDirectoryExists($this->projectDir . '/.cursor/skills');
        // Should have the skills from templates/skills/
        $this->assertFileExists($this->projectDir . '/.cursor/skills/create-pr/SKILL.md');
        $this->assertFileExists($this->projectDir . '/.cursor/skills/start-ticket/SKILL.md');
        $this->assertFileExists($this->projectDir . '/.cursor/skills/create-linear-tickets/SKILL.md');
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
