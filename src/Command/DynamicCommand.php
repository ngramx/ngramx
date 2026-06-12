<?php

declare(strict_types=1);

namespace Ngramx\Command;

use Ngramx\Config\LockFile;
use Ngramx\Config\RecommendedCommands;
use Ngramx\Config\Schema\CommandDefinition;
use Ngramx\Config\Schema\NgramxConfig;
use Ngramx\Orchestrator\CommandOrchestrator;
use Ngramx\Output\OutputFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DynamicCommand extends Command
{
    private readonly LockFile $lockFile;

    public function __construct(
        string $name,
        private readonly CommandDefinition $commandDef,
        private readonly NgramxConfig $config,
        private readonly CommandOrchestrator $orchestrator,
        ?LockFile $lockFile = null,
    ) {
        parent::__construct($name);
        $this->lockFile = $lockFile ?? new LockFile();
    }

    protected function configure(): void
    {
        $this->setDescription($this->commandDef->description);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);

        try {
            $commandName = $this->getName();
            if ($commandName === null) {
                throw new \RuntimeException('Command name is not set');
            }

            if (trim($this->commandDef->command) === '') {
                $this->showEmptyCommandError($formatter, $commandName);
                return Command::FAILURE;
            }

            // Resolve the Docker namespace the environment was started with so
            // custom commands target the right containers. Without this, running
            // a command from a per-ticket worktree (whose `ngramx up` wrote a
            // namespaced project name to .ngramx.lock) would look for containers
            // under the default project name and wrongly report "not running".
            $namespace = $this->lockFile->exists()
                ? $this->lockFile->read()?->namespace
                : null;

            $executionTime = $this->orchestrator->run($commandName, $this->config, $namespace);

            $output->writeln('');
            $output->writeln(sprintf(
                '<fg=#7D55C7>Command completed successfully (%.1fs)</>',
                $executionTime
            ));
            $output->writeln('');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $formatter->error("Error: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    private function showEmptyCommandError(OutputFormatter $formatter, string $commandName): void
    {
        $formatter->error("Command '$commandName' is not yet configured.");
        $formatter->getOutput()->writeln('');

        $recommended = RecommendedCommands::COMMANDS[$commandName] ?? null;
        $example = $recommended['example'] ?? 'your-command-here';

        $formatter->getOutput()->writeln('  Define it in ngramx.yml:');
        $formatter->getOutput()->writeln('');
        $formatter->getOutput()->writeln("    <fg=cyan>$commandName:</>");
        $formatter->getOutput()->writeln("      <fg=cyan>command: \"$example\"</>");
        $formatter->getOutput()->writeln("      <fg=cyan>description: \"{$this->commandDef->description}\"</>");
        $formatter->getOutput()->writeln('');
    }
}
