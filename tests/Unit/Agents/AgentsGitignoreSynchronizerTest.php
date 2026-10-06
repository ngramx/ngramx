<?php

declare(strict_types=1);

namespace Tests\Unit\Agents;

use Ngramx\Agents\AgentsGitignoreSynchronizer;
use PHPUnit\Framework\TestCase;

class AgentsGitignoreSynchronizerTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = sys_get_temp_dir() . '/ngramx_gitignore_test_' . uniqid();
        mkdir($this->projectDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $path = $this->projectDir . '/.gitignore';
        if (is_file($path)) {
            unlink($path);
        }
        rmdir($this->projectDir);
    }

    public function test_sync_appends_a_block_that_ignores_generated_files_only(): void
    {
        file_put_contents($this->projectDir . '/.gitignore', "/vendor\n");

        $sync = new AgentsGitignoreSynchronizer();
        $this->assertTrue($sync->sync($this->projectDir));

        $contents = file_get_contents($this->projectDir . '/.gitignore');
        $this->assertIsString($contents);
        $this->assertStringContainsString("/vendor\n", $contents);
        $this->assertStringContainsString('/CLAUDE.md', $contents);
        $this->assertStringContainsString('/.claude/rules/ngramx.md', $contents);
        $this->assertStringContainsString('/.cursor/rules/ngramx.mdc', $contents);
        $this->assertStringContainsString('/.cursor/skills/ngramx-*/', $contents);
        $this->assertStringContainsString('/.claude/skills/ngramx-*/', $contents);
        $this->assertStringNotContainsString('/AGENTS.md', $contents);
        $this->assertFalse($sync->sync($this->projectDir));
    }

    public function test_sync_replaces_the_legacy_per_skill_block(): void
    {
        file_put_contents($this->projectDir . '/.gitignore', <<<'GITIGNORE'
/vendor

# Ngramx-generated agent files (rewritten locally; do not commit)
/AGENTS.md
/CLAUDE.md
/.cursor/rules/ngramx.mdc
/.github/copilot-instructions.md
/.cursor/skills/create-pr/
/.claude/skills/start-ticket/

/build
GITIGNORE);

        $sync = new AgentsGitignoreSynchronizer();
        $this->assertTrue($sync->sync($this->projectDir));

        $contents = file_get_contents($this->projectDir . '/.gitignore');
        $this->assertIsString($contents);
        $this->assertStringContainsString("/vendor\n", $contents);
        $this->assertStringContainsString("/build\n", $contents);
        $this->assertStringNotContainsString('Ngramx-generated agent files', $contents);
        $this->assertStringNotContainsString('/AGENTS.md', $contents);
        $this->assertStringContainsString('/.cursor/skills/ngramx-*/', $contents);
        $this->assertStringContainsString('/.cursor/skills/create-pr/', $contents);
        $this->assertSame(1, substr_count($contents, '/.cursor/skills/create-pr/'));
        $this->assertSame(1, substr_count($contents, AgentsGitignoreSynchronizer::BEGIN));
    }
}
