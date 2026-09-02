<?php

declare(strict_types=1);

namespace Ngramx;

use GuzzleHttp\Client;
use Ngramx\Agents\AgentsMdSynchronizer;
use Ngramx\Agents\AgentsSyncOrchestrator;
use Ngramx\Caddy\CaddyService;
use Ngramx\Codabyte\ServerTargetResolver;
use Ngramx\Codabyte\SshRunner;
use Ngramx\Command\Codabyte\LoginCommand as CodabyteLoginCommand;
use Ngramx\Command\DownCommand;
use Ngramx\Command\DynamicCommand;
use Ngramx\Command\InitCommand;
use Ngramx\Command\InitGithubActionsCommand;
use Ngramx\Command\InitPostmacloneWorkflowCommand;
use Ngramx\Command\LogsCommand;
use Ngramx\Command\N8n\ExportCommand;
use Ngramx\Command\N8n\ImportCommand;
use Ngramx\Command\N8n\NormaliseCommand;
use Ngramx\Command\PostmacloneCommand;
use Ngramx\Command\RebuildCommand;
use Ngramx\Command\ReviewCommand;
use Ngramx\Command\SecureCommand;
use Ngramx\Command\SelfUpdateCommand;
use Ngramx\Command\ShellCommand;
use Ngramx\Command\ShowUrlCommand;
use Ngramx\Command\StatusCommand;
use Ngramx\Command\StyleDemoCommand;
use Ngramx\Command\SyncAgentsCommand;
use Ngramx\Command\UpCommand;
use Ngramx\Command\WorktreeCommand;
use Ngramx\Config\ConfigLoader;
use Ngramx\Config\ConfigWarningChecker;
use Ngramx\Config\Exception\ConfigException;
use Ngramx\Config\LockFile;
use Ngramx\Config\Validator\ConfigValidator;
use Ngramx\Console\CommandGroupArgv;
use Ngramx\Docker\ComposeOverrideGenerator;
use Ngramx\Docker\ContainerExecutor;
use Ngramx\Docker\DockerCompose;
use Ngramx\Docker\DockerLauncher;
use Ngramx\Docker\HealthChecker;
use Ngramx\Docker\ImageReuser;
use Ngramx\Docker\NamespaceResolver;
use Ngramx\Docker\PortOffsetManager;
use Ngramx\Docker\ServiceReadinessWaiter;
use Ngramx\Executor\HostCommandExecutor;
use Ngramx\Git\GitExcludeManager;
use Ngramx\Git\GitRepositoryService;
use Ngramx\Herd\HerdService;
use Ngramx\Laravel\LaravelLogParser;
use Ngramx\Laravel\LaravelService;
use Ngramx\Orchestrator\CommandOrchestrator;
use Ngramx\Orchestrator\SetupOrchestrator;
use Ngramx\Output\OutputFormatter;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

class Application extends BaseApplication
{
    private const SKIP_WARNINGS_FOR = [
        'init', 'self-update', 'list', 'help', '_complete', 'completion', 'style-demo',
        'up', 'rebuild', 'codabyte:login',
    ];

    /**
     * Commands for which the AGENTS.md sync should not run. These are read-only / utility
     * commands that must not cause filesystem writes to the user's project. The sync-agents
     * command is excluded because it performs its own sync inside execute().
     */
    private const SKIP_AGENTS_SYNC_FOR = [
        '_complete', 'completion', 'list', 'help', 'self-update', 'style-demo', 'sync-agents',
        'postmaclone', 'codabyte:login',
    ];

    /**
     * Command groups that may also be typed with a space, so `ngramx codabyte
     * login` reaches `codabyte:login`.
     */
    private const COMMAND_GROUPS = ['codabyte', 'n8n'];

    /** @var list<string> */
    private array $configWarnings = [];

    /** @var list<string> */
    private array $configLoadErrors = [];

    /** @return list<string> */
    public function getConfigWarnings(): array
    {
        return $this->configWarnings;
    }

    /**
     * Errors surfaced while attempting to load `ngramx.yml` during boot.
     *
     * The constructor used to swallow these silently to allow commands like
     * `help` and `self-update` to work from any directory, but that also
     * meant that a malformed config caused subsequent commands to behave
     * oddly with no explanation. Now we capture them here and let
     * `doRunCommand` decide whether to surface them based on which command
     * is running.
     *
     * @return list<string>
     */
    public function getConfigLoadErrors(): array
    {
        return $this->configLoadErrors;
    }
    public function run(?InputInterface $input = null, ?OutputInterface $output = null): int
    {
        if ($input === null) {
            /** @var list<string> $argv */
            $argv = $_SERVER['argv'] ?? [];
            $input = new ArgvInput(CommandGroupArgv::rewrite($argv, self::COMMAND_GROUPS));
        }

        return parent::run($input, $output);
    }

    protected function getDefaultCommands(): array
    {
        // The `_complete` command is the engine that powers tab completion at runtime;
        // the `completion` command dumps the static shell script that wires it up. Both
        // work inside the PHAR as long as Symfony Console's `Resources/completion.*`
        // templates are packaged (see the dedicated finder entry in box.json).
        return [
            new \Symfony\Component\Console\Command\HelpCommand(),
            new \Symfony\Component\Console\Command\ListCommand(),
            new \Symfony\Component\Console\Command\CompleteCommand(),
            new \Symfony\Component\Console\Command\DumpCompletionCommand(),
        ];
    }

    public function __construct()
    {
        parent::__construct('Ngramx CLI', '2.42.0');

        // Simple dependency injection
        $configValidator = new ConfigValidator();
        $configLoader = new ConfigLoader($configValidator);
        $dockerCompose = new DockerCompose();
        $dockerLauncher = new DockerLauncher($dockerCompose);
        $hostExecutor = new HostCommandExecutor();
        $containerExecutor = new ContainerExecutor();
        $healthChecker = new HealthChecker();

        // Multi-instance support services
        $lockFile = new LockFile();
        $namespaceResolver = new NamespaceResolver();
        $portOffsetManager = new PortOffsetManager();
        $overrideGenerator = new ComposeOverrideGenerator();
        $herdService = new HerdService();
        $caddyService = new CaddyService();

        // Create output formatter for orchestrators
        $consoleOutput = new ConsoleOutput();
        $outputFormatter = new OutputFormatter($consoleOutput);

        // Create orchestrators
        $setupOrchestrator = new SetupOrchestrator(
            $dockerCompose,
            $hostExecutor,
            $healthChecker,
            $outputFormatter
        );

        $commandOrchestrator = new CommandOrchestrator(
            $outputFormatter,
            new ServiceReadinessWaiter(
                $dockerCompose,
                $healthChecker,
                $outputFormatter,
                $containerExecutor,
            ),
            dockerCompose: $dockerCompose,
        );

        // Create Git and Laravel services
        $gitRepositoryService = new GitRepositoryService();
        $laravelService = new LaravelService($containerExecutor);
        $logParser = new LaravelLogParser();

        // Register built-in commands (these take precedence over custom commands)
        $this->add(new InitCommand());
        $this->add(new CodabyteLoginCommand(new SshRunner(), ServerTargetResolver::fromEnvironment()));
        $this->add(new InitGithubActionsCommand());
        $this->add(new InitPostmacloneWorkflowCommand());
        $this->add(new SyncAgentsCommand());
        $this->add(new UpCommand(
            $configLoader,
            $setupOrchestrator,
            $lockFile,
            $namespaceResolver,
            $portOffsetManager,
            $overrideGenerator,
            $dockerCompose,
            $herdService,
            $caddyService,
            $dockerLauncher
        ));
        $this->add(new DownCommand(
            $configLoader,
            $dockerCompose,
            $lockFile,
            $overrideGenerator,
            $herdService
        ));
        $this->add(new ReviewCommand(
            $configLoader,
            $dockerCompose,
            $lockFile,
            $gitRepositoryService,
            $laravelService,
            $commandOrchestrator,
            $portOffsetManager,
            new GitExcludeManager(),
            $namespaceResolver,
            new ImageReuser()
        ));
        $this->add(new WorktreeCommand(
            $configLoader,
            $dockerCompose,
            $lockFile,
            $gitRepositoryService,
            $laravelService,
            $commandOrchestrator,
            $portOffsetManager,
            new GitExcludeManager(),
            $namespaceResolver,
            new ImageReuser()
        ));
        $this->add(new StatusCommand(
            $configLoader,
            $dockerCompose,
            $healthChecker,
            $lockFile
        ));
        $this->add(new ShellCommand(
            $configLoader,
            $containerExecutor,
            $lockFile
        ));
        $this->add(new LogsCommand(
            $configLoader,
            $containerExecutor,
            $lockFile,
            $laravelService,
            $logParser
        ));
        $this->add(new SelfUpdateCommand());
        $this->add(new ShowUrlCommand(
            $configLoader,
            $lockFile,
            $portOffsetManager
        ));
        $this->add(new SecureCommand($configLoader));
        $this->add(new PostmacloneCommand($configLoader));
        $this->add(new StyleDemoCommand());

        // Create HTTP client for n8n export command
        $httpClient = new Client([
            'timeout' => 10,
            'verify' => false,
        ]);

        $this->add(new ExportCommand(
            $configLoader,
            $httpClient
        ));

        $this->add(new ImportCommand(
            $configLoader,
            $httpClient
        ));

        $this->add(new NormaliseCommand(
            $configLoader,
            $httpClient
        ));

        $this->add(new RebuildCommand(
            $configLoader,
            $dockerCompose,
            $healthChecker,
            $commandOrchestrator,
            $lockFile,
            $overrideGenerator,
            $dockerLauncher
        ));

        // Try to load ngramx.yml and register custom commands dynamically.
        //
        // The "no ngramx.yml in scope" case is silent on purpose — users
        // are allowed to run `ngramx --version`, `ngramx help`, etc. from
        // anywhere. But if a ngramx.yml DOES exist and we couldn't parse
        // it, that almost always means a real config bug, and silently
        // swallowing the error leaves the user staring at a CLI that's
        // missing all their custom commands with no explanation.
        $configPath = null;
        try {
            $configPath = $configLoader->findConfigFile();
        } catch (ConfigException) {
            // No ngramx.yml found in cwd or its parents — fine.
        }

        if ($configPath !== null) {
            try {
                $config = $configLoader->load($configPath);

                // Register each custom command as a real command
                foreach ($config->commands as $name => $cmdDef) {
                    // Skip if command name conflicts with built-in commands
                    if ($this->has($name)) {
                        continue;
                    }

                    $this->add(new DynamicCommand(
                        $name,
                        $cmdDef,
                        $config,
                        $commandOrchestrator,
                        $lockFile
                    ));
                }

                // Check for missing recommended commands
                $warningChecker = new ConfigWarningChecker();
                $this->configWarnings = $warningChecker->check($config);
            } catch (\Throwable $e) {
                $this->configLoadErrors[] = sprintf(
                    'Failed to load %s — %s',
                    $configPath,
                    $e->getMessage(),
                );
            }
        }
    }

    protected function doRunCommand(Command $command, InputInterface $input, OutputInterface $output): int
    {
        if (!in_array($command->getName(), self::SKIP_AGENTS_SYNC_FOR, true)) {
            $this->synchronizeAgentsMdInProject();
        }

        $quiet = $this->suppressesChrome($command, $input);

        if ($this->configLoadErrors !== [] && !$quiet) {
            $formatter = new OutputFormatter($output);
            foreach ($this->configLoadErrors as $error) {
                $formatter->warning("  ⚠ $error");
            }
            $formatter->info('Custom commands from `ngramx.yml` are unavailable until the file is fixed.');
            $output->writeln('');
        }

        if ($this->configWarnings !== [] && !$quiet) {
            $formatter = new OutputFormatter($output);
            foreach ($this->configWarnings as $warning) {
                $formatter->warning("  ⚠ $warning");
            }
            $output->writeln('');
        }

        return parent::doRunCommand($command, $input, $output);
    }

    /**
     * Whether to withhold the warning banners that normally precede a command.
     *
     * Beyond the per-command opt-out list, `--json` suppresses them: these
     * banners are written to stdout, so a single missing recommended command in
     * `ngramx.yml` would prepend two lines of prose to what a caller is about to
     * hand to a JSON parser. The advice is worth having, but not at the cost of
     * making machine-readable output unparseable on most real projects.
     */
    private function suppressesChrome(Command $command, InputInterface $input): bool
    {
        if (in_array($command->getName(), self::SKIP_WARNINGS_FOR, true)) {
            return true;
        }

        // Checked against the raw parameters: the command's own definition has
        // not necessarily been bound yet at this point.
        return $input->hasParameterOption('--json', true);
    }

    private function synchronizeAgentsMdInProject(): void
    {
        try {
            $configLoader = new ConfigLoader(new ConfigValidator());
            $configPath = $configLoader->findConfigFile();
            $projectRoot = dirname($configPath);
            $config = $configLoader->load($configPath);
            (new AgentsSyncOrchestrator())->sync($projectRoot, $config->agents);
        } catch (ConfigException) {
            // No ngramx.yml in cwd or parents — fall back to AGENTS.md only
            try {
                $cwd = getcwd();
                if ($cwd !== false) {
                    (new AgentsMdSynchronizer())->sync($cwd);
                }
            } catch (\Throwable) {
                // Silently skip
            }
        }
    }
}
