<?php

declare(strict_types=1);

namespace Ngramx\Agents;

use Ngramx\Agents\TargetWriter\ClaudeMdWriter;
use Ngramx\Agents\TargetWriter\CopilotInstructionsWriter;
use Ngramx\Agents\TargetWriter\CursorRulesWriter;
use Ngramx\Agents\TargetWriter\TargetWriterInterface;
use Ngramx\Config\Schema\AgentsConfig;

/**
 * Orchestrates the sync of agent instructions to all configured targets.
 *
 * Generated files go under .cursor/ and .claude/ (and optional Copilot
 * instructions). Top-level AGENTS.md is not written. A previously dumped
 * managed block is removed so the file can stay project-owned.
 */
final class AgentsSyncOrchestrator
{
    /** @var array<string, TargetWriterInterface> */
    private readonly array $writers;

    public function __construct(
        private readonly AgentsMdSynchronizer $agentsMdSync = new AgentsMdSynchronizer(),
        private readonly SkillsSynchronizer $skillsSync = new SkillsSynchronizer(),
        private readonly AgentsGitignoreSynchronizer $gitignoreSync = new AgentsGitignoreSynchronizer(),
        ?CursorRulesWriter $cursorRulesWriter = null,
        ?ClaudeMdWriter $claudeMdWriter = null,
        ?CopilotInstructionsWriter $copilotWriter = null,
    ) {
        $this->writers = [
            'cursor_rules' => $cursorRulesWriter ?? new CursorRulesWriter(),
            'claude_md' => $claudeMdWriter ?? new ClaudeMdWriter(),
            'copilot_instructions' => $copilotWriter ?? new CopilotInstructionsWriter(),
        ];
    }

    /**
     * Sync all configured targets for the given project.
     *
     * @return array{targets_changed: list<string>, skills_changed: bool, gitignore_changed: bool}
     */
    public function sync(string $projectRoot, AgentsConfig $config): array
    {
        $projectRoot = rtrim($projectRoot, '/');
        $targetsChanged = [];

        if ($this->agentsMdSync->retireManagedBlock($projectRoot)) {
            $targetsChanged[] = 'agents_md_retired';
        }

        $markdown = (new AgentsManagedBodyProvider())->getMarkdown();

        foreach ($config->targets as $target) {
            if ($target === 'agents_md' || !isset($this->writers[$target])) {
                continue;
            }

            if ($this->writers[$target]->write($projectRoot, $markdown)) {
                $targetsChanged[] = $target;
            }
        }

        $skillsChanged = $this->skillsSync->sync($projectRoot, $config->skills);
        $gitignoreChanged = $this->gitignoreSync->sync($projectRoot);

        return [
            'targets_changed' => $targetsChanged,
            'skills_changed' => $skillsChanged,
            'gitignore_changed' => $gitignoreChanged,
        ];
    }

    /**
     * Convenience method: sync using default config (for when no ngramx.yml is available).
     *
     * @return array{targets_changed: list<string>, skills_changed: bool, gitignore_changed: bool}
     */
    public function syncWithDefaults(string $projectRoot): array
    {
        return $this->sync($projectRoot, new AgentsConfig());
    }
}
