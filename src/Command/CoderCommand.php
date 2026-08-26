<?php

declare(strict_types=1);

namespace Ngramx\Command;

use Ngramx\Output\OutputFormatter;
use Ngramx\Remote\CoderTargetResolver;
use Ngramx\Remote\SshRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CoderCommand extends Command
{
    public function __construct(
        private readonly SshRunner $sshRunner,
        private readonly CoderTargetResolver $resolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('coder')
            ->setDescription('SSH to the coding agent server and drop into the Claude Code container')
            ->setHelp(<<<'HELP'
Opens an interactive shell inside the container that runs Claude Code on the
remote coding agent server.

  <info>ngramx coder</info>                       Shell inside the container
  <info>ngramx coder -- claude --version</info>   Run one command and exit
  <info>ngramx coder --server</info>              Stop on the server, outside the container
  <info>ngramx coder --root</info>                Shell in as root
  <info>ngramx coder --dry-run</info>             Print the ssh command instead of running it

Defaults can be overridden per-machine with NGRAMX_CODER_HOST,
NGRAMX_CODER_SSH_USER, NGRAMX_CODER_CONTAINER, NGRAMX_CODER_CONTAINER_USER,
NGRAMX_CODER_WORKDIR and NGRAMX_CODER_PORT.
HELP)
            ->addArgument(
                'cmd',
                InputArgument::IS_ARRAY,
                'Command to run instead of an interactive shell (prefix with --)'
            )
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Server hostname')
            ->addOption('ssh-user', 'u', InputOption::VALUE_REQUIRED, 'SSH user on the server')
            ->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'SSH port')
            ->addOption('container', 'c', InputOption::VALUE_REQUIRED, 'Container name to enter')
            ->addOption('container-user', null, InputOption::VALUE_REQUIRED, 'User to run as inside the container')
            ->addOption('workdir', 'w', InputOption::VALUE_REQUIRED, 'Working directory inside the container')
            ->addOption('root', null, InputOption::VALUE_NONE, 'Shorthand for --container-user=root')
            ->addOption('server', 's', InputOption::VALUE_NONE, 'Stay on the server rather than entering the container')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the ssh command instead of running it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);

        $containerUser = $input->getOption('root')
            ? 'root'
            : $this->stringOption($input, 'container-user');

        $target = $this->resolver->resolve([
            'host' => $this->stringOption($input, 'host'),
            'ssh-user' => $this->stringOption($input, 'ssh-user'),
            'port' => $this->stringOption($input, 'port'),
            'container' => $this->stringOption($input, 'container'),
            'container-user' => $containerUser,
            'workdir' => $this->stringOption($input, 'workdir'),
        ]);

        /** @var list<string> $command */
        $command = $input->getArgument('cmd');
        $insideContainer = !$input->getOption('server');

        $args = $target->sshArgs($command, $insideContainer, $this->sshRunner->hasTty());

        if ($input->getOption('dry-run')) {
            $output->writeln($this->shellJoin($args));

            return Command::SUCCESS;
        }

        if ($command === []) {
            $destination = $insideContainer
                ? $target->container . ' on ' . $target->host
                : $target->host;
            $formatter->info("Connecting to {$destination}...");
        }

        return $this->sshRunner->run($args);
    }

    /**
     * Render an argv as a paste-safe shell line, quoting only the arguments that
     * actually need it so the common case stays readable.
     *
     * @param list<string> $args
     */
    private function shellJoin(array $args): string
    {
        return implode(' ', array_map(
            static fn (string $arg): string => preg_match('#^[A-Za-z0-9@._/:=-]+$#', $arg) === 1
                ? $arg
                : escapeshellarg($arg),
            $args
        ));
    }

    private function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
