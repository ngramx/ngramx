<?php

declare(strict_types=1);

namespace Ngramx\Command;

use Exception;
use Ngramx\Config\ConfigLoader;
use Ngramx\Config\Exception\ConfigException;
use Ngramx\Config\LockFile;
use Ngramx\Config\Schema\NgramxConfig;
use Ngramx\Docker\DockerCompose;
use Ngramx\Docker\ImageReuser;
use Ngramx\Docker\NamespaceResolver;
use Ngramx\Docker\PortOffsetManager;
use Ngramx\Git\GitExcludeManager;
use Ngramx\Git\GitRepositoryService;
use Ngramx\Laravel\LaravelService;
use Ngramx\Orchestrator\CommandOrchestrator;
use Ngramx\Output\OutputFormatter;
use Ngramx\Worktree\WorktreeIdentity;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class ReviewCommand extends Command
{
    private const WORKTREE_DIR = '.ngramx/worktrees';
    private const EXCLUDE_ENTRY = '/.ngramx/worktrees/';

    /**
     * Dependency directories copied from the parent checkout into a fresh worktree
     * so the install step (composer install / npm ci) is a near-instant no-op
     * instead of a cold download. Git ignores these, so the copy is safe.
     *
     * @var list<string>
     */
    private const DEPENDENCY_DIRS = ['vendor', 'node_modules'];

    private readonly PortOffsetManager $portOffsetManager;
    private readonly GitExcludeManager $gitExcludeManager;
    private readonly NamespaceResolver $namespaceResolver;
    private readonly ImageReuser $imageReuser;

    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly DockerCompose $dockerCompose,
        private readonly LockFile $lockFile,
        private readonly GitRepositoryService $gitRepositoryService,
        private readonly LaravelService $laravelService,
        private readonly CommandOrchestrator $commandOrchestrator,
        ?PortOffsetManager $portOffsetManager = null,
        ?GitExcludeManager $gitExcludeManager = null,
        ?NamespaceResolver $namespaceResolver = null,
        ?ImageReuser $imageReuser = null,
    ) {
        parent::__construct();
        $this->portOffsetManager = $portOffsetManager ?? new PortOffsetManager();
        $this->gitExcludeManager = $gitExcludeManager ?? new GitExcludeManager();
        $this->namespaceResolver = $namespaceResolver ?? new NamespaceResolver();
        $this->imageReuser = $imageReuser ?? new ImageReuser();
    }

    protected function configure(): void
    {
        $this
            ->setName('review')
            ->setDescription('Prepare the development environment for reviewing a ticket by checking out its branch and resetting the database')
            ->addArgument('ticket', InputArgument::REQUIRED, 'The ticket number to prepare for review')
            ->addOption('quick', null, InputOption::VALUE_NONE, 'Use the "clear" command instead of "fresh" — skips the database reset. Only safe on branches with no schema or seed changes.')
            ->addOption('worktree', 'w', InputOption::VALUE_NONE, 'Review in an isolated git worktree + parallel dev environment under .ngramx/worktrees/ instead of checking the branch out in place')
            ->addOption('cursor', null, InputOption::VALUE_NONE, 'Open the worktree in a new Cursor window once it is ready (implies --worktree)')
            ->addOption('cleanup', null, InputOption::VALUE_NONE, 'Stop and remove the worktree + parallel environment created for this ticket');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);
        $ticketNumber = $input->getArgument('ticket');

        // --cursor implies worktree mode: you can only open a parallel Cursor window
        // if there is a separate worktree for it to live in.
        $worktreeMode = (bool) $input->getOption('worktree') || (bool) $input->getOption('cursor');

        try {
            $configPath = $this->configLoader->findConfigFile();
            $config = $this->configLoader->load($configPath);

            $primaryService = $config->docker->primaryService;
            $composeFile = $config->docker->composeFile;
            $repositoryPath = dirname($configPath);

            if ((bool) $input->getOption('cleanup')) {
                return $this->runWorktreeCleanup($output, $formatter, $repositoryPath, $ticketNumber);
            }

            if (!$worktreeMode) {
                $namespace = null;
                if ($this->lockFile->exists()) {
                    $lockData = $this->lockFile->read();
                    $namespace = $lockData?->namespace;
                }

                if (!$this->dockerCompose->isRunning($composeFile, $namespace)) {
                    $formatter->error('Services are not running. Please run "ngramx up" first.');
                    return Command::FAILURE;
                }
            }

            $formatter->section("Preparing for Ticket Review: $ticketNumber");

            $formatter->info('Fetching latest changes from origin...');
            if (!$this->gitRepositoryService->fetchFromOrigin($repositoryPath)) {
                $formatter->error('Failed to fetch from origin. Make sure you have git configured on your host machine and have access to the repository.');
                return Command::FAILURE;
            }

            $formatter->info('Searching for branches containing ticket number...');
            $branchNames = $this->gitRepositoryService->findBranchesContaining($repositoryPath, $ticketNumber);
            if (empty($branchNames)) {
                $formatter->error("No branches found containing ticket number: $ticketNumber");
                return Command::FAILURE;
            }

            $selectedBranch = $this->gitRepositoryService->selectBranch(
                $repositoryPath,
                $branchNames,
                $input,
                $output,
                fn (string $message) => $formatter->info($message),
                fn (string $message) => $formatter->warning($message),
                fn (string $branch) => str_starts_with($branch, $ticketNumber)
            );

            if ($worktreeMode) {
                return $this->runWorktreeReview(
                    $input,
                    $output,
                    $formatter,
                    $config,
                    $repositoryPath,
                    $selectedBranch,
                    $ticketNumber
                );
            }

            $formatter->info("Checking out branch: $selectedBranch");
            if (!$this->gitRepositoryService->checkoutBranch($repositoryPath, $selectedBranch)) {
                $message = "Failed to check out branch '$selectedBranch'.";

                $details = trim($this->gitRepositoryService->lastCheckoutError());
                if ($details !== '') {
                    $message .= "\n\n" . OutputFormatter::escape($details);
                } else {
                    $message .= "\n\nThis usually means your working tree has uncommitted changes or "
                        . 'the local branch has diverged from origin. Commit, stash, or discard your '
                        . 'changes and try again.';
                }

                $formatter->error($message);
                return Command::FAILURE;
            }

            $namespace = null;
            if ($this->lockFile->exists()) {
                $namespace = $this->lockFile->read()?->namespace;
            }

            $resetResult = $this->runReset($input, $formatter, $config, $composeFile, $primaryService, $namespace);
            if ($resetResult !== Command::SUCCESS) {
                return $resetResult;
            }

            $formatter->success("✓ Successfully prepared for ticket $ticketNumber review on branch $selectedBranch");

            $this->displayCompletionUrls($repositoryPath, $ticketNumber, $formatter);

            $output->writeln('');

            return Command::SUCCESS;
        } catch (ConfigException $e) {
            $formatter->error("Configuration error: {$e->getMessage()}");
            return Command::FAILURE;
        } catch (Exception $e) {
            $formatter->error("Error: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    /**
     * Review a ticket in an isolated git worktree with its own parallel dev
     * environment (separate Docker project + ticket-prefixed *.localhost URL),
     * leaving the main checkout untouched so other tickets can be reviewed in
     * parallel.
     */
    private function runWorktreeReview(
        InputInterface $input,
        OutputInterface $output,
        OutputFormatter $formatter,
        NgramxConfig $config,
        string $repositoryPath,
        string $selectedBranch,
        string $ticketNumber
    ): int {
        $ticketSlug = WorktreeIdentity::deriveTicketSlug($selectedBranch, $ticketNumber);
        $repoName = WorktreeIdentity::sanitizeSegment(basename($repositoryPath));
        $folderName = WorktreeIdentity::folderName($ticketSlug, $repoName);
        $namespace = WorktreeIdentity::namespaceFor($folderName);
        $worktreePath = $repositoryPath . '/' . self::WORKTREE_DIR . '/' . $folderName;

        // Compute the port offset up-front (from the shared compose file) so we can
        // bake the final URL into the worktree .env before the env is brought up.
        $basePorts = $this->portOffsetManager->extractBasePorts($config->docker->composeFile);
        $portOffset = $this->portOffsetManager->findAvailableOffset($basePorts);
        $worktreeUrl = WorktreeIdentity::buildUrl($config->docker->appUrl, $folderName, $portOffset);

        if ($this->gitRepositoryService->worktreeExists($repositoryPath, $worktreePath)) {
            $formatter->info("Reusing existing worktree: $worktreePath");
        } else {
            $formatter->info("Creating worktree for $selectedBranch at .ngramx/worktrees/$folderName");
            if (!$this->gitRepositoryService->addWorktree($repositoryPath, $worktreePath, $selectedBranch)) {
                $formatter->error('Failed to create git worktree. The branch may already be checked out elsewhere — switch the main checkout off it or remove the stale worktree, then retry.');
                return Command::FAILURE;
            }
        }

        // Hide worktrees from the parent checkout's git status and Cursor indexer.
        $this->gitExcludeManager->ensureExcluded($repositoryPath, self::EXCLUDE_ENTRY);
        $this->gitExcludeManager->ensureCursorIgnored($repositoryPath, self::EXCLUDE_ENTRY);

        $this->seedWorktreeEnv($repositoryPath, $worktreePath, $worktreeUrl, $formatter);
        $this->primeWorktreeDependencies($repositoryPath, $worktreePath, $formatter);

        $originalCwd = getcwd();
        if ($originalCwd === false || chdir($worktreePath) === false) {
            $formatter->error("Failed to switch into the worktree directory: $worktreePath");
            return Command::FAILURE;
        }

        try {
            $alreadyRunning = file_exists($worktreePath . '/.ngramx.lock');

            if ($alreadyRunning) {
                $formatter->info('Worktree environment is already running — skipping startup.');
            } else {
                $formatter->section('Starting isolated environment');

                // Reuse the main checkout's already-built image so the worktree
                // doesn't rebuild it from scratch under its own project name.
                $imageSources = array_values(array_unique([
                    $repoName,
                    $this->namespaceResolver->deriveFromDirectory($repositoryPath),
                ]));
                $reused = $this->imageReuser->reuse($config->docker->composeFile, $imageSources, $namespace);
                if ($reused !== []) {
                    $formatter->info('Reusing existing image for: ' . implode(', ', $reused) . ' (skipping rebuild)');
                }

                $upExit = $this->runUpCommand($output, $namespace, $portOffset);
                if ($upExit !== Command::SUCCESS) {
                    $formatter->error('Failed to start the worktree environment.');
                    return $upExit;
                }
            }

            $worktreeConfig = $this->configLoader->load($worktreePath . '/ngramx.yml');

            $resetResult = $this->runReset(
                $input,
                $formatter,
                $worktreeConfig,
                $worktreeConfig->docker->composeFile,
                $worktreeConfig->docker->primaryService,
                $namespace
            );
            if ($resetResult !== Command::SUCCESS) {
                return $resetResult;
            }
        } finally {
            chdir($originalCwd);
        }

        $formatter->success("✓ Worktree ready for ticket $ticketNumber on branch $selectedBranch");
        $output->writeln('');
        $formatter->url('Application', $worktreeUrl);
        $formatter->url('Worktree', $worktreePath);

        $this->displayCompletionUrls($worktreePath, $ticketNumber, $formatter);

        if ((bool) $input->getOption('cursor')) {
            $this->openCursorWindow($worktreePath, $formatter);
        }

        $output->writeln('');

        return Command::SUCCESS;
    }

    /**
     * Stop and remove the worktree (and its parallel Docker environment) created
     * for a ticket, reclaiming containers, volumes and disk space.
     */
    private function runWorktreeCleanup(
        OutputInterface $output,
        OutputFormatter $formatter,
        string $repositoryPath,
        string $ticketNumber
    ): int {
        $matches = $this->findWorktreesForTicket($repositoryPath, $ticketNumber);

        if ($matches === []) {
            $formatter->error("No worktree found for ticket $ticketNumber under .ngramx/worktrees/");
            return Command::FAILURE;
        }

        if (count($matches) > 1) {
            $formatter->error("Multiple worktrees match ticket $ticketNumber — be more specific:");
            foreach ($matches as $match) {
                $formatter->info('  - ' . basename($match));
            }
            return Command::FAILURE;
        }

        $worktreePath = $matches[0];
        $formatter->section('Cleaning up worktree: ' . basename($worktreePath));

        if (file_exists($worktreePath . '/.ngramx.lock')) {
            $originalCwd = getcwd();
            if ($originalCwd !== false && chdir($worktreePath) !== false) {
                try {
                    $formatter->info('Stopping worktree environment...');
                    $this->runDownCommand($output);
                } finally {
                    chdir($originalCwd);
                }
            }
        } else {
            $formatter->info('No running environment detected for this worktree.');
        }

        $this->gitRepositoryService->removeWorktree($repositoryPath, $worktreePath);

        // The container may have written root-owned files (e.g. composer running as
        // root), which the host user can't delete — so `git worktree remove` can
        // leave the directory behind. Force-remove it, falling back to a root
        // container when needed, then prune the worktree admin entry.
        if (is_dir($worktreePath)) {
            $this->forceRemoveDirectory($worktreePath, $formatter);
            $this->gitRepositoryService->pruneWorktrees($repositoryPath);
        }

        if (is_dir($worktreePath)) {
            $formatter->error('Failed to remove the worktree directory. Remove it manually: ' . $worktreePath);
            return Command::FAILURE;
        }

        $formatter->success("✓ Removed worktree for ticket $ticketNumber");
        $output->writeln('');

        return Command::SUCCESS;
    }

    /**
     * Remove a directory, falling back to a short-lived root container when the
     * host user lacks permission (containers that run as root leave root-owned
     * files in the bind-mounted worktree).
     */
    private function forceRemoveDirectory(string $path, OutputFormatter $formatter): void
    {
        $hostRemove = new Process(['rm', '-rf', $path]);
        $hostRemove->setTimeout(120);
        $hostRemove->run();

        if (!is_dir($path)) {
            return;
        }

        $formatter->info('Removing container-owned files via a helper container...');

        $base = dirname($path);
        $folder = basename($path);
        $containerRemove = new Process([
            'docker', 'run', '--rm',
            '-v', $base . ':/cleanup',
            'alpine', 'rm', '-rf', '/cleanup/' . $folder,
        ]);
        $containerRemove->setTimeout(120);
        $containerRemove->run();
    }

    /**
     * Find worktree directories under .ngramx/worktrees/ whose folder name
     * contains the ticket (case-insensitive).
     *
     * @return list<string> Absolute paths to matching worktree directories
     */
    private function findWorktreesForTicket(string $repositoryPath, string $ticketNumber): array
    {
        $worktreesDir = $repositoryPath . '/' . self::WORKTREE_DIR;

        if (!is_dir($worktreesDir)) {
            return [];
        }

        $entries = scandir($worktreesDir);
        if ($entries === false) {
            return [];
        }

        $needle = mb_strtolower($ticketNumber);
        $matches = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $worktreesDir . '/' . $entry;
            if (is_dir($path) && str_contains(mb_strtolower($entry), $needle)) {
                $matches[] = $path;
            }
        }

        return $matches;
    }

    /**
     * Tear down the worktree environment by invoking the `down` command in-process.
     * Volumes are removed because the worktree itself is about to be deleted.
     */
    private function runDownCommand(OutputInterface $output): int
    {
        $application = $this->getApplication();
        if ($application === null) {
            return Command::FAILURE;
        }

        $downInput = new ArrayInput(['--volumes' => true]);
        $downInput->setInteractive(false);

        return $application->find('down')->run($downInput, $output);
    }

    /**
     * Run the database/cache reset step (fresh, or clear with --quick), honouring
     * a project-defined command in ngramx.yml and otherwise falling back to the
     * default Laravel reset. Returns a Command status code.
     */
    private function runReset(
        InputInterface $input,
        OutputFormatter $formatter,
        NgramxConfig $config,
        string $composeFile,
        string $primaryService,
        ?string $namespace
    ): int {
        $resetCommand = $input->getOption('quick') ? 'clear' : 'fresh';

        if (isset($config->commands[$resetCommand]) && trim($config->commands[$resetCommand]->command) !== '') {
            $formatter->section("Running $resetCommand");
            $this->commandOrchestrator->run($resetCommand, $config, $namespace);

            return Command::SUCCESS;
        }

        $formatter->warning("Command '$resetCommand' is not defined in ngramx.yml — falling back to default Laravel reset");

        if (!$this->laravelService->hasArtisan($composeFile, $primaryService, $namespace)) {
            $formatter->warning('Laravel artisan not found, skipping environment reset');

            return Command::SUCCESS;
        }

        $formatter->info('Clearing application caches...');
        if (!$this->laravelService->clearCaches($composeFile, $primaryService, $namespace)) {
            $formatter->error('Failed to clear caches');
            return Command::FAILURE;
        }

        $formatter->info('Resetting development database...');
        if (!$this->laravelService->resetDatabase($composeFile, $primaryService, $namespace)) {
            $formatter->error('Failed to reset database');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Bring up the worktree environment by invoking the `up` command in-process,
     * with an explicit namespace + port offset so it never collides with the main
     * checkout. Verification and the secure-cert prompt are skipped to keep it
     * non-interactive and avoid false failures against the swapped host URL.
     */
    private function runUpCommand(OutputInterface $output, string $namespace, int $portOffset): int
    {
        $application = $this->getApplication();
        if ($application === null) {
            return Command::FAILURE;
        }

        $upCommand = $application->find('up');

        $upInput = new ArrayInput([
            '--namespace' => $namespace,
            '--port-offset' => (string) $portOffset,
            '--no-verify' => true,
            '--no-prompt-secure' => true,
        ]);
        $upInput->setInteractive(false);

        return $upCommand->run($upInput, $output);
    }

    /**
     * Copy dependency directories (vendor, node_modules) from the parent checkout
     * into a fresh worktree so the install step is near-instant instead of a cold
     * download. Skipped per-directory when the parent lacks it or the worktree
     * already has it (e.g. a reused worktree).
     */
    private function primeWorktreeDependencies(string $repositoryPath, string $worktreePath, OutputFormatter $formatter): void
    {
        foreach (self::DEPENDENCY_DIRS as $dir) {
            $source = $repositoryPath . '/' . $dir;
            $target = $worktreePath . '/' . $dir;

            if (!is_dir($source) || file_exists($target)) {
                continue;
            }

            $formatter->info("Priming $dir from parent checkout...");

            // -a preserves symlinks/permissions; reflink=auto is a fast copy-on-write
            // clone on supporting filesystems and falls back to a normal copy otherwise.
            $process = new Process(['cp', '-a', '--reflink=auto', $source, $target]);
            $process->setTimeout(300);
            $process->run();

            if (!$process->isSuccessful()) {
                // Non-fatal: the install step will simply repopulate it.
                $formatter->warning("Could not copy $dir into the worktree — the install step will fetch it instead.");
            }
        }
    }

    /**
     * Seed the worktree's .env from the parent checkout (which is almost certainly
     * already working) and patch APP_URL to the worktree's URL so app-generated
     * links/cookies stay within this environment. Host ports are remapped at the
     * compose layer, so the rest of the parent env is safe to copy verbatim.
     */
    private function seedWorktreeEnv(string $repositoryPath, string $worktreePath, string $worktreeUrl, OutputFormatter $formatter): void
    {
        $parentEnv = $repositoryPath . '/.env';
        $worktreeEnv = $worktreePath . '/.env';

        if (!file_exists($worktreeEnv) && file_exists($parentEnv)) {
            if (@copy($parentEnv, $worktreeEnv)) {
                $formatter->info('Copied .env from parent checkout');
            }
        }

        if (!file_exists($worktreeEnv)) {
            return;
        }

        $contents = file_get_contents($worktreeEnv);
        if ($contents === false) {
            return;
        }

        $patched = $this->patchEnvVar($contents, 'APP_URL', $worktreeUrl);
        if ($patched !== $contents) {
            file_put_contents($worktreeEnv, $patched);
            $formatter->info("Set APP_URL to $worktreeUrl");
        }
    }

    /**
     * Replace (or append) a KEY=value line in a .env file's contents.
     */
    private function patchEnvVar(string $contents, string $key, string $value): string
    {
        $line = $key . '=' . $value;
        $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';

        if (preg_match($pattern, $contents) === 1) {
            return preg_replace($pattern, $line, $contents) ?? $contents;
        }

        $separator = ($contents === '' || str_ends_with($contents, "\n")) ? '' : "\n";

        return $contents . $separator . $line . "\n";
    }

    /**
     * Open the worktree in a new Cursor window, degrading gracefully when the
     * `cursor` CLI is not available on PATH.
     */
    private function openCursorWindow(string $worktreePath, OutputFormatter $formatter): void
    {
        $probe = Process::fromShellCommandline('command -v cursor');
        $probe->run();

        if (!$probe->isSuccessful()) {
            $formatter->warning('The `cursor` command was not found on your PATH.');
            $formatter->info("Open it manually with: cursor $worktreePath");
            return;
        }

        try {
            $process = new Process(['cursor', $worktreePath]);
            $process->setTimeout(null);
            $process->start();
            $formatter->info('Opening Cursor...');
        } catch (\Throwable) {
            $formatter->warning('Could not open Cursor automatically.');
            $formatter->info("Open it manually with: cursor $worktreePath");
        }
    }

    /**
     * Look for a completion.md (case-insensitive) in .ngramx/tickets/<ticket>/ and display any URLs found.
     */
    private function displayCompletionUrls(string $repositoryPath, string $ticketNumber, OutputFormatter $formatter): void
    {
        $ticketDir = $this->findTicketDirectory($repositoryPath, $ticketNumber);

        if ($ticketDir === null) {
            return;
        }

        $completionFile = $this->findCompletionFile($ticketDir);

        if ($completionFile === null) {
            return;
        }

        $contents = file_get_contents($completionFile);
        if ($contents === false) {
            return;
        }

        $urls = $this->parseCompletionUrls($contents);

        if ($urls === []) {
            return;
        }

        $formatter->getOutput()->writeln('');
        foreach ($urls as $label => $url) {
            $formatter->url($label, $url);
        }
    }

    /**
     * Find a ticket folder that matches the ticket number (case-insensitive, contains-match).
     * E.g. ticket number "1603" matches folder "gig-1603", "GIG-1603" matches "gig-1603", etc.
     */
    private function findTicketDirectory(string $repositoryPath, string $ticketNumber): ?string
    {
        $ticketsDir = $repositoryPath . '/.ngramx/tickets';

        if (!is_dir($ticketsDir)) {
            return null;
        }

        $entries = scandir($ticketsDir);
        if ($entries === false) {
            return null;
        }

        $needle = mb_strtolower($ticketNumber);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (str_contains(mb_strtolower($entry), $needle)) {
                $path = $ticketsDir . '/' . $entry;
                if (is_dir($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * Case-insensitive search for completion.md.
     */
    private function findCompletionFile(string $ticketDir): ?string
    {
        $files = scandir($ticketDir);
        if ($files === false) {
            return null;
        }

        foreach ($files as $file) {
            if (strcasecmp($file, 'completion.md') === 0) {
                return $ticketDir . '/' . $file;
            }
        }

        return null;
    }

    /**
     * @return array<string, string> label => URL
     */
    private function parseCompletionUrls(string $contents): array
    {
        $urls = [];

        if (preg_match_all('/^-\s*(.+?):\s*(https?:\/\/\S+)/m', $contents, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $urls[trim($match[1])] = trim($match[2]);
            }
        }

        return $urls;
    }
}
