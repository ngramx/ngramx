<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema;

readonly class NgramxConfig
{
    /**
     * @param array<string, CommandDefinition> $commands
     */
    public function __construct(
        public string $version,
        public DockerConfig $docker,
        public SetupConfig $setup,
        public N8nConfig $n8n,
        public SecretsConfig $secrets = new SecretsConfig(),
        public AgentsConfig $agents = new AgentsConfig(),
        public array $commands = [],
    ) {
    }
}
