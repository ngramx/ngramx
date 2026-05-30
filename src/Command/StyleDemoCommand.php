<?php

declare(strict_types=1);

namespace Ngramx\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class StyleDemoCommand extends Command
{
    // Gigabyte Brand Colors
    private const COLOR_TEAL = '#2ED9C3';
    private const COLOR_PURPLE = '#7D55C7';
    private const COLOR_SMOKE = '#D2DCE5';

    protected function configure(): void
    {
        $this
            ->setName('demo')
            ->setDescription('Demo different output styles');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->showStyle1($output);
        $output->writeln('');
        $output->writeln('');

        $this->showStyle2($output);
        $output->writeln('');
        $output->writeln('');

        $this->showStyle3($output);

        return Command::SUCCESS;
    }

    private function showStyle1(OutputInterface $output): void
    {
        $output->writeln('<fg=' . self::COLOR_PURPLE . '>════════════════════════════════════════</>');
        $output->writeln('<fg=' . self::COLOR_PURPLE . '> STYLE 1: Minimalist</>');
        $output->writeln('<fg=' . self::COLOR_PURPLE . '>════════════════════════════════════════</>');
        $output->writeln('');

        $output->writeln('<fg=' . self::COLOR_SMOKE . '>Loaded configuration from: /path/to/ngramx.yml</>');
        $output->writeln('');

        $output->writeln('<fg=' . self::COLOR_TEAL . '>>> Pre-start commands</>');
        $output->writeln('<fg=' . self::COLOR_SMOKE . '>  Pre-start commands will be executed here</>');
        $output->writeln('');

        $output->writeln('<fg=' . self::COLOR_TEAL . '>>> Starting Docker services</>');
        $output->writeln('<fg=' . self::COLOR_SMOKE . '>  Docker services started</>');
        $output->writeln('');

        $output->writeln('<fg=' . self::COLOR_TEAL . '>>> Waiting for services</>');
        $output->writeln('<fg=' . self::COLOR_SMOKE . '>  Service health checks will be implemented in Phase 2</>');
        $output->writeln('');

        $output->writeln('<fg=' . self::COLOR_TEAL . '>>> Initialize commands</>');
        $output->writeln('<fg=' . self::COLOR_SMOKE . '>  Initialize commands will be executed here</>');
        $output->writeln('');

        $output->writeln('<fg=green>Environment ready! (2.5s)</>');
    }

    private function showStyle2(OutputInterface $output): void
    {
        $output->writeln('<fg=' . self::COLOR_PURPLE . '>────────────────────────────────────────</>');
        $output->writeln('<fg=' . self::COLOR_PURPLE . '> STYLE 2: Bullet Points</>');
        $output->writeln('<fg=' . self::COLOR_PURPLE . '>────────────────────────────────────────</>');
        $output->writeln('');

        $output->writeln('<fg=' . self::COLOR_SMOKE . '>Loaded configuration from: /path/to/ngramx.yml</>');
        $output->writeln('');

        $output->writeln('<fg=' . self::COLOR_TEAL . '>• Pre-start commands</>');
        $output->writeln('<fg=' . self::COLOR_SMOKE . '>  Pre-start commands will be executed here</>');
        $output->writeln('');

        $output->writeln('<fg=' . self::COLOR_TEAL . '>• Starting Docker services</>');
        $output->writeln('<fg=' . self::COLOR_SMOKE . '>  Docker services started</>');
        $output->writeln('');

        $output->writeln('<fg=' . self::COLOR_TEAL . '>• Waiting for services</>');
        $output->writeln('<fg=' . self::COLOR_SMOKE . '>  Service health checks will be implemented in Phase 2</>');
        $output->writeln('');

        $output->writeln('<fg=' . self::COLOR_TEAL . '>• Initialize commands</>');
        $output->writeln('<fg=' . self::COLOR_SMOKE . '>  Initialize commands will be executed here</>');
        $output->writeln('');

        $output->writeln('<fg=green>Environment ready! (2.5s)</>');
    }

    private function showStyle3(OutputInterface $output): void
    {
        $output->writeln('<fg=' . self::COLOR_PURPLE . '>╔════════════════════════════════════════╗</>');
        $output->writeln('<fg=' . self::COLOR_PURPLE . '>║ STYLE 3: Boxed                         ║</>');
        $output->writeln('<fg=' . self::COLOR_PURPLE . '>╚════════════════════════════════════════╝</>');
        $output->writeln('');

        $output->writeln('<fg=' . self::COLOR_SMOKE . '>Loaded configuration from: /path/to/ngramx.yml</>');
        $output->writeln('');

        $output->writeln('<fg=' . self::COLOR_TEAL . '>▸ Pre-start commands</>');
        $output->writeln('<fg=' . self::COLOR_SMOKE . '>  Pre-start commands will be executed here</>');
        $output->writeln('');

        $output->writeln('<fg=' . self::COLOR_TEAL . '>▸ Starting Docker services</>');
        $output->writeln('<fg=' . self::COLOR_SMOKE . '>  Docker services started</>');
        $output->writeln('');

        $output->writeln('<fg=' . self::COLOR_TEAL . '>▸ Waiting for services</>');
        $output->writeln('<fg=' . self::COLOR_SMOKE . '>  Service health checks will be implemented in Phase 2</>');
        $output->writeln('');

        $output->writeln('<fg=' . self::COLOR_TEAL . '>▸ Initialize commands</>');
        $output->writeln('<fg=' . self::COLOR_SMOKE . '>  Initialize commands will be executed here</>');
        $output->writeln('');

        $output->writeln('<fg=green>Environment ready! (2.5s)</>');
    }
}
