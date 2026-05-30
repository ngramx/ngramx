<?php

declare(strict_types=1);

namespace Cortex\Command;

use Cortex\Agents\AgentsMdSynchronizer;
use Cortex\Agents\AgentsSyncOrchestrator;
use Cortex\Config\ConfigLoader;
use Cortex\Config\Exception\ConfigException;
use Cortex\Config\Validator\ConfigValidator;
use Cortex\Output\OutputFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SyncAgentsCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('sync-agents')
            ->setDescription('Update Cortex-managed agent instructions for all configured targets');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);

        try {
            $configLoader = new ConfigLoader(new ConfigValidator());
            $configPath = $configLoader->findConfigFile();
            $projectRoot = dirname($configPath);
            $config = $configLoader->load($configPath);
        } catch (ConfigException) {
            $formatter->error('No cortex.yml found in this directory or parent directories.');

            return Command::FAILURE;
        }

        $sync = new AgentsMdSynchronizer();
        if ($sync->hasMalformedManagedMarkers($projectRoot)) {
            $formatter->warning(
                'AGENTS.md contains malformed CORTEX_AGENTS_MANAGED markers (e.g. END before BEGIN, '
                . 'or a BEGIN with no matching END). Cortex will not modify the file until the '
                . 'markers are fixed or the managed block is removed.'
            );

            return Command::FAILURE;
        }

        $orchestrator = new AgentsSyncOrchestrator();
        $result = $orchestrator->sync($projectRoot, $config->agents);

        $targetsChanged = $result['targets_changed'];
        $skillsChanged = $result['skills_changed'];

        if ($targetsChanged === [] && !$skillsChanged) {
            $formatter->info('All agent targets are already up to date.');

            return Command::SUCCESS;
        }

        if ($targetsChanged !== []) {
            foreach ($targetsChanged as $target) {
                $formatter->success("✓ Updated: $target");
            }
        }

        if ($skillsChanged) {
            $formatter->success('✓ Skills synchronized to: ' . implode(', ', $config->agents->skills));
        }

        return Command::SUCCESS;
    }
}
