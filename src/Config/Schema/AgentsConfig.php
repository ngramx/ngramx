<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema;

readonly class AgentsConfig
{
    public const DEFAULT_TARGETS = ['cursor_rules', 'claude_md'];

    public const DEFAULT_SKILLS = ['cursor', 'claude'];

    /**
     * `agents_md` is still accepted so existing configs load, but it is not
     * written. Top-level AGENTS.md stays project-owned.
     */
    public const VALID_TARGETS = [
        'agents_md',
        'cursor_rules',
        'claude_md',
        'copilot_instructions',
    ];

    public const VALID_SKILLS = [
        'cursor',
        'claude',
    ];

    /**
     * @param list<string> $targets
     * @param list<string> $skills
     */
    public function __construct(
        public array $targets = self::DEFAULT_TARGETS,
        public array $skills = self::DEFAULT_SKILLS,
    ) {
    }
}
