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
 */
final class AgentsSyncOrchestrator
{
    /** @var array<string, TargetWriterInterface> */
    private readonly array $writers;

    public function __construct(
        private readonly AgentsMdSynchronizer $agentsMdSync = new AgentsMdSynchronizer(),
        private readonly SkillsSynchronizer $skillsSync = new SkillsSynchronizer(),
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
     * @return array{targets_changed: list<string>, skills_changed: bool}
     */
    public function sync(string $projectRoot, AgentsConfig $config): array
    {
        $projectRoot = rtrim($projectRoot, '/');
        $targetsChanged = [];

        // AGENTS.md is always synced if in targets
        if (in_array('agents_md', $config->targets, true)) {
            if ($this->agentsMdSync->sync($projectRoot)) {
                $targetsChanged[] = 'agents_md';
            }
        }

        // Get the markdown content for other targets
        $bodyProvider = new AgentsManagedBodyProvider();
        $markdown = $bodyProvider->getMarkdown();

        // Write to other configured targets
        foreach ($config->targets as $target) {
            if ($target === 'agents_md') {
                continue;
            }

            if (isset($this->writers[$target])) {
                if ($this->writers[$target]->write($projectRoot, $markdown)) {
                    $targetsChanged[] = $target;
                }
            }
        }

        // Sync skills
        $skillsChanged = $this->skillsSync->sync($projectRoot, $config->skills);

        return [
            'targets_changed' => $targetsChanged,
            'skills_changed' => $skillsChanged,
        ];
    }

    /**
     * Convenience method: sync using default config (for when no ngramx.yml is available).
     *
     * @return array{targets_changed: list<string>, skills_changed: bool}
     */
    public function syncWithDefaults(string $projectRoot): array
    {
        return $this->sync($projectRoot, new AgentsConfig());
    }
}
