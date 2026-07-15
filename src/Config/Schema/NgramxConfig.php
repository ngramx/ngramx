<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema;

readonly class NgramxConfig
{
    public const DEFAULT_TEAM = 'gig';

    /**
     * @param array<string, CommandDefinition> $commands
     * @param string $defaultTeam Ticket-team prefix used to expand bare ticket
     *        numbers (e.g. `ngramx worktree 2345` => `gig-2345`).
     */
    public function __construct(
        public string $version,
        public DockerConfig $docker,
        public SetupConfig $setup,
        public N8nConfig $n8n,
        public SecretsConfig $secrets = new SecretsConfig(),
        public AgentsConfig $agents = new AgentsConfig(),
        public array $commands = [],
        public string $defaultTeam = self::DEFAULT_TEAM,
    ) {
    }
}
