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
use Ngramx\Host\EtcHostsHint;
use Ngramx\Http\CompletionUrlRewriter;
use Ngramx\Http\UrlPortOffset;
use Ngramx\Laravel\LaravelService;
use Ngramx\Orchestrator\CommandOrchestrator;
use Ngramx\Output\OutputFormatter;
use Ngramx\Worktree\CursorIpcHookResolver;
use Ngramx\Worktree\WorktreeCertSeeder;
use Ngramx\Worktree\WorktreeDependencyPrimer;
use Ngramx\Worktree\WorktreeIdentity;
use Ngramx\Worktree\WorktreeOwnershipReconciler;
use Ngramx\Worktree\WorktreeUrlResolver;
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

    private readonly PortOffsetManager $portOffsetManager;
    private readonly GitExcludeManager $gitExcludeManager;
    private readonly NamespaceResolver $namespaceResolver;
    private readonly ImageReuser $imageReuser;
    private readonly WorktreeOwnershipReconciler $ownershipReconciler;
    private readonly WorktreeUrlResolver $worktreeUrlResolver;
    private readonly WorktreeDependencyPrimer $dependencyPrimer;
    private readonly WorktreeCertSeeder $certSeeder;

    public function __construct(
        protected readonly ConfigLoader $configLoader,
        private readonly DockerCompose $dockerCompose,
        private readonly LockFile $lockFile,
        protected readonly GitRepositoryService $gitRepositoryService,
        private readonly LaravelService $laravelService,
        private readonly CommandOrchestrator $commandOrchestrator,
        ?PortOffsetManager $portOffsetManager = null,
        ?GitExcludeManager $gitExcludeManager = null,
        ?NamespaceResolver $namespaceResolver = null,
        ?ImageReuser $imageReuser = null,
        ?WorktreeOwnershipReconciler $ownershipReconciler = null,
        ?WorktreeUrlResolver $worktreeUrlResolver = null,
        ?WorktreeDependencyPrimer $dependencyPrimer = null,
        ?WorktreeCertSeeder $certSeeder = null,
    ) {
        parent::__construct();
        $this->portOffsetManager = $portOffsetManager ?? new PortOffsetManager();
        $this->gitExcludeManager = $gitExcludeManager ?? new GitExcludeManager();
        $this->namespaceResolver = $namespaceResolver ?? new NamespaceResolver();
        $this->imageReuser = $imageReuser ?? new ImageReuser();
        $this->ownershipReconciler = $ownershipReconciler ?? new WorktreeOwnershipReconciler();
        $this->worktreeUrlResolver = $worktreeUrlResolver ?? new WorktreeUrlResolver();
        $this->dependencyPrimer = $dependencyPrimer ?? new WorktreeDependencyPrimer();
        $this->certSeeder = $certSeeder ?? new WorktreeCertSeeder();
    }

    protected function configure(): void
    {
        $this
            ->setName('review')
            ->setDescription('Prepare the development environment for reviewing a ticket by checking out its branch and resetting the database')
            ->addArgument('ticket', InputArgument::OPTIONAL, 'The ticket number to prepare for review. Optional with --cleanup, where it targets a single worktree (omit it to clean up every worktree).')
            ->addOption('quick', null, InputOption::VALUE_NONE, 'Use the "clear" command instead of "fresh" — skips the database reset. Only safe on branches with no schema or seed changes.')
            ->addOption('worktree', 'w', InputOption::VALUE_NONE, 'Review in an isolated git worktree + parallel dev environment under .ngramx/worktrees/ instead of checking the branch out in place')
            ->addOption('cursor', 'c', InputOption::VALUE_NONE, 'Open the worktree in a new Cursor window once it is ready (implies --worktree)')
            ->addOption('cleanup', null, InputOption::VALUE_NONE, 'Stop and remove worktree(s) + parallel environments. Targets one ticket when given, or every worktree when no ticket is provided.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);
        $ticketArgument = $input->getArgument('ticket');
        $ticketNumber = is_string($ticketArgument) ? trim($ticketArgument) : '';
        $hasTicket = $ticketNumber !== '';

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
                return $hasTicket
                    ? $this->runWorktreeCleanup($output, $formatter, $repositoryPath, $ticketNumber)
                    : $this->runWorktreeCleanupAll($output, $formatter, $repositoryPath);
            }

            if (!$hasTicket) {
                $formatter->error(
                    'A ticket number is required to prepare a review. '
                    . 'To remove worktrees instead, pass --cleanup (with a ticket to target one, or alone to remove them all).'
                );
                return Command::FAILURE;
            }

            if (!$worktreeMode) {
                $namespace = null;
                $portOffset = 0;
                if ($this->lockFile->exists()) {
                    $lockData = $this->lockFile->read();
                    $namespace = $lockData?->namespace;
                    $portOffset = $lockData->portOffset ?? 0;
                }

                // Rather than refusing when the stack is down, start it for the
                // developer — `review` already knows how to bring an environment
                // up, so making them run `ngramx up` first is needless friction.
                // Reuse the namespace/offset the lock recorded so the URL and
                // container names stay stable across the restart.
                if (!$this->dockerCompose->isRunning($composeFile, $namespace)) {
                    $formatter->info('Services are not running — starting them first...');

                    // A leftover lock (containers gone, lock left behind) would
                    // make `up` refuse with "already running", so clear it first.
                    if ($this->lockFile->exists()) {
                        $this->lockFile->delete();
                    }

                    $upExit = $this->runUpCommand($output, $namespace, $portOffset);
                    if ($upExit !== Command::SUCCESS) {
                        $formatter->error('Failed to start the environment. Run `ngramx up` manually to see the full output.');
                        return $upExit;
                    }
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
            $portOffset = 0;
            $portMap = [];
            if ($this->lockFile->exists()) {
                $lockData = $this->lockFile->read();
                if ($lockData !== null) {
                    $namespace = $lockData->namespace;
                    $portOffset = $lockData->portOffset ?? 0;
                    $portMap = $lockData->portMap;
                }
            }

            $resetResult = $this->runReset($input, $formatter, $config, $composeFile, $primaryService, $namespace);
            if ($resetResult !== Command::SUCCESS) {
                return $resetResult;
            }

            $formatter->success("✓ Successfully prepared for ticket $ticketNumber review on branch $selectedBranch");

            // Targeted conflict resolution (`up` with no explicit offset) records
            // a per-port map instead of an offset — the printed URL and the
            // localised completion.json deep-links must follow the web port
            // wherever the map moved it.
            $environmentUrl = UrlPortOffset::applyMap(
                UrlPortOffset::apply($config->docker->appUrl, $portOffset),
                $portMap
            );
            $this->displayCompletionUrls($repositoryPath, $ticketNumber, $formatter, $environmentUrl);

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
    protected function runWorktreeReview(
        InputInterface $input,
        OutputInterface $output,
        OutputFormatter $formatter,
        NgramxConfig $config,
        string $repositoryPath,
        string $selectedBranch,
        string $ticketNumber,
        bool $createNewBranch = false,
        bool $popStash = false
    ): int {
        $ticketSlug = WorktreeIdentity::deriveTicketSlug($selectedBranch, $ticketNumber);
        $repoName = WorktreeIdentity::sanitizeSegment(basename($repositoryPath));
        $folderName = WorktreeIdentity::folderName($ticketSlug, $repoName);
        $namespace = WorktreeIdentity::namespaceFor($folderName);
        $worktreePath = $repositoryPath . '/' . self::WORKTREE_DIR . '/' . $folderName;

        // Resolve the port offset up-front so we can bake the final URL into the
        // worktree .env before the env is brought up. A worktree that is already
        // running reuses the offset its lock file recorded, so the URL never drifts
        // from the live stack; a fresh one allocates a free offset from the shared
        // compose file.
        $portOffset = $this->resolveWorktreePortOffset($worktreePath, $config);

        // Seed .env with the app's own host + offset port before startup — always a
        // valid origin. Once the stack is up we may upgrade this to the prettier
        // "<folder>.localhost" subdomain if the app turns out to be host-agnostic
        // (see the post-start resolver below).
        $worktreeUrl = UrlPortOffset::apply($config->docker->appUrl, $portOffset);

        if ($this->gitRepositoryService->worktreeExists($repositoryPath, $worktreePath)) {
            $formatter->info("Reusing existing worktree: $worktreePath");
        } elseif ($createNewBranch) {
            $formatter->info("Creating new branch $selectedBranch with worktree at .ngramx/worktrees/$folderName");
            if (!$this->gitRepositoryService->addWorktreeWithNewBranch($repositoryPath, $worktreePath, $selectedBranch)) {
                $formatter->error("Failed to create the new branch '$selectedBranch'. It may already exist and be checked out elsewhere — switch that checkout off it or pick a different name, then retry.");
                return Command::FAILURE;
            }
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

        $this->seedWorktreeConfig($repositoryPath, $worktreePath, $formatter);
        $this->seedWorktreeEnv($repositoryPath, $worktreePath, $worktreeUrl, $formatter);

        // For https apps, make sure the worktree's cert covers both hostnames
        // the environment may advertise (the app's own host and the
        // "<folder>.localhost" subdomain) BEFORE the stack starts, so the proxy
        // boots with the right cert. Any pre_start cert hook then sees the
        // files already present and leaves them alone.
        $certChanged = $this->certSeeder->seed(
            $repositoryPath,
            $worktreePath,
            $config->docker->appUrl,
            $config->docker->sslPath,
            $folderName,
            $formatter
        );

        // Start the dependency copies but don't wait: image reuse and `up` only
        // need the worktree directory, .env and ngramx.yml (seeded above), so
        // the copy latency hides behind the (typically slower) Docker startup.
        // The copies use absolute paths, so starting before chdir is safe.
        $this->dependencyPrimer->start($repositoryPath, $worktreePath, $formatter);

        $originalCwd = getcwd();
        // @ suppresses the PHP warning for a missing directory — the failure is
        // handled explicitly with a clearer message just below.
        if ($originalCwd === false || @chdir($worktreePath) === false) {
            $this->dependencyPrimer->await($formatter);
            $formatter->error("Failed to switch into the worktree directory: $worktreePath");
            return Command::FAILURE;
        }

        if ($popStash) {
            $formatter->info('Restoring stashed changes into the worktree...');
            if (!$this->gitRepositoryService->stashPop($worktreePath)) {
                $formatter->warning(
                    'Could not restore stashed changes automatically. Run `git stash pop` in the worktree to recover them.'
                );
            }
        }

        try {
            $worktreeLock = new LockFile($worktreePath);

            // Decide "already running?" from the actual container state rather
            // than the mere presence of a lock file. A lock left behind by a
            // previous run (machine reboot, `docker compose down` elsewhere, a
            // crash) makes the worktree *look* running while its containers are
            // long gone — we'd then skip startup and fail downstream in the reset
            // step with "Service 'app' is not running". Probing the containers is
            // the only reliable signal.
            $alreadyRunning = $this->dockerCompose->isRunning($config->docker->composeFile, $namespace);

            if ($alreadyRunning) {
                $formatter->info('Worktree environment is already running — skipping startup.');

                // The proxy read the old cert at startup; restart in place so
                // it serves the one that now covers the worktree hostname.
                if ($certChanged) {
                    $formatter->info('Restarting services so the proxy picks up the updated TLS certificate...');
                    try {
                        $this->dockerCompose->restart($config->docker->composeFile, $namespace);
                    } catch (Exception $e) {
                        $formatter->warning('Could not restart services automatically: ' . $e->getMessage());
                        $formatter->info('Restart them manually so HTTPS uses the new certificate.');
                    }
                }
            } else {
                $formatter->section('Starting isolated environment');

                // A leftover lock would make `up` refuse with "already running",
                // so clear it first: the containers it pointed at are not up.
                if ($worktreeLock->exists()) {
                    $formatter->info('Clearing a stale lock from a previous run...');
                    $worktreeLock->delete();
                }

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

            // The lock file is the single source of truth for the offset that is
            // actually live, so derive everything from it — this is what kills URL
            // drift when a previous run picked a different offset.
            $liveLock = $worktreeLock->read();
            if ($liveLock !== null && $liveLock->portOffset !== null) {
                $portOffset = $liveLock->portOffset;
            }
            // When `up` resolved conflicts per-port instead of via an offset, the
            // lock carries a port map — the advertised URL (and the completion
            // deep-links rewritten onto it) must follow the web port's remap.
            $portMap = $liveLock->portMap ?? [];

            // Now the app is up, decide the final URL: the pretty
            // "<folder>.localhost" subdomain for host-agnostic apps (typical
            // Laravel), or the app's own host for host-routed ones (e.g. apache
            // vhosts). Re-seed .env so the app's self-generated links match what we
            // print; the reset step below boots/clears with the corrected APP_URL.
            $resolvedUrl = UrlPortOffset::applyMap(
                $this->worktreeUrlResolver->resolve(
                    $config->docker->appUrl,
                    $folderName,
                    $portOffset
                ),
                $portMap
            );
            if ($resolvedUrl !== $worktreeUrl) {
                $worktreeUrl = $resolvedUrl;
                $this->seedWorktreeEnv($repositoryPath, $worktreePath, $worktreeUrl, $formatter);
            }

            // The reset/install step is the first thing that reads vendor and
            // node_modules, so the priming copies must have landed by now.
            $this->dependencyPrimer->await($formatter);

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

            $this->reconcileWorktreeOwnership($worktreePath, $formatter);
        } finally {
            // Idempotent re-await covering the early-return paths (`up` failure
            // etc.), so a still-running copy is never leaked or torn down under.
            $this->dependencyPrimer->await($formatter);
            chdir($originalCwd);
        }

        $formatter->success("✓ Worktree ready for ticket $ticketNumber on branch $selectedBranch");
        $output->writeln('');
        $formatter->url('Application', $worktreeUrl);
        $formatter->url('Worktree', $worktreePath);

        // Option A keeps the app's own host, which (unlike a *.localhost name) may
        // not resolve on the developer's machine — point them at the /etc/hosts fix.
        $hostsLine = EtcHostsHint::suggestedHostsLine($worktreeUrl);
        if ($hostsLine !== null) {
            $output->writeln('');
            $formatter->warning('This hostname does not resolve on your machine yet (normal for made-up dev domains).');
            $formatter->info('Add this line to /etc/hosts so your browser can open the URL:');
            $formatter->info('  ' . $hostsLine);
        }

        $this->displayCompletionUrls($worktreePath, $ticketNumber, $formatter, $worktreeUrl);

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
    protected function runWorktreeCleanup(
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

        if (!$this->teardownWorktree($output, $formatter, $repositoryPath, $worktreePath)) {
            return Command::FAILURE;
        }

        $formatter->success("✓ Removed worktree for ticket $ticketNumber");
        $output->writeln('');

        return Command::SUCCESS;
    }

    /**
     * Stop and remove every worktree (and its parallel Docker environment) created
     * under .ngramx/worktrees/. Used by `review --cleanup` with no ticket argument
     * to reclaim every parallel review environment in one pass.
     */
    protected function runWorktreeCleanupAll(
        OutputInterface $output,
        OutputFormatter $formatter,
        string $repositoryPath
    ): int {
        $worktrees = $this->listWorktreeDirectories($repositoryPath);

        if ($worktrees === []) {
            $formatter->info('No worktrees found under .ngramx/worktrees/ — nothing to clean up.');
            return Command::SUCCESS;
        }

        $formatter->section('Cleaning up all worktrees (' . count($worktrees) . ')');

        $failed = [];
        foreach ($worktrees as $worktreePath) {
            $formatter->info('Removing worktree: ' . basename($worktreePath));
            if (!$this->teardownWorktree($output, $formatter, $repositoryPath, $worktreePath)) {
                $failed[] = basename($worktreePath);
            }
        }

        if ($failed !== []) {
            $formatter->error('Failed to remove ' . count($failed) . ' worktree(s): ' . implode(', ', $failed));
            return Command::FAILURE;
        }

        $formatter->success('✓ Removed all worktrees (' . count($worktrees) . ')');
        $output->writeln('');

        return Command::SUCCESS;
    }

    /**
     * Stop a single worktree's environment (if running) and delete its directory,
     * pruning the git worktree admin entry. Returns false if the directory could
     * not be removed.
     */
    private function teardownWorktree(
        OutputInterface $output,
        OutputFormatter $formatter,
        string $repositoryPath,
        string $worktreePath
    ): bool {
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
            return false;
        }

        return true;
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
        $needle = mb_strtolower($ticketNumber);

        return array_values(array_filter(
            $this->listWorktreeDirectories($repositoryPath),
            fn (string $path): bool => str_contains(mb_strtolower(basename($path)), $needle)
        ));
    }

    /**
     * List every worktree directory under .ngramx/worktrees/.
     *
     * @return list<string> Absolute paths to worktree directories
     */
    private function listWorktreeDirectories(string $repositoryPath): array
    {
        $worktreesDir = $repositoryPath . '/' . self::WORKTREE_DIR;

        if (!is_dir($worktreesDir)) {
            return [];
        }

        $entries = scandir($worktreesDir);
        if ($entries === false) {
            return [];
        }

        $matches = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $worktreesDir . '/' . $entry;
            if (is_dir($path)) {
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
     * Bring up an environment by invoking the `up` command in-process. Used for
     * both the worktree review (an explicit namespace + port offset keeps it from
     * colliding with the main checkout) and the in-place review (namespace null,
     * offset reused from the lock). Verification and the secure-cert prompt are
     * skipped to keep it non-interactive and avoid false failures against the
     * swapped host URL.
     */
    private function runUpCommand(OutputInterface $output, ?string $namespace, int $portOffset): int
    {
        $application = $this->getApplication();
        if ($application === null) {
            return Command::FAILURE;
        }

        $upCommand = $application->find('up');

        // Only pass --namespace when we actually have one: the default
        // (non-worktree) checkout runs without namespace isolation, and `up`
        // treats a null namespace option as "no isolation". Passing an explicit
        // null through ArrayInput for a VALUE_REQUIRED option is invalid.
        $arguments = [
            '--port-offset' => (string) $portOffset,
            '--no-verify' => true,
            '--no-prompt-secure' => true,
        ];
        if ($namespace !== null) {
            $arguments['--namespace'] = $namespace;
        }

        $upInput = new ArrayInput($arguments);
        $upInput->setInteractive(false);

        return $upCommand->run($upInput, $output);
    }

    /**
     * Resolve the port offset for a worktree. A worktree that is already running
     * reuses the offset recorded in its lock file (the single source of truth for
     * the live stack), so the printed URL and APP_URL never drift; otherwise a
     * free offset is allocated from the shared compose file's exposed ports.
     */
    private function resolveWorktreePortOffset(string $worktreePath, NgramxConfig $config): int
    {
        $existing = (new LockFile($worktreePath))->read();
        if ($existing !== null) {
            return $existing->portOffset ?? 0;
        }

        $basePorts = $this->portOffsetManager->extractBasePorts($config->docker->composeFile);

        return $this->portOffsetManager->findAvailableOffset($basePorts);
    }

    /**
     * Restore ownership of the worktree's bind-mounted files to the developer's
     * uid/gid so the container's non-root runtime user can write storage/ and
     * bootstrap/cache. Delegated to {@see WorktreeOwnershipReconciler}, which
     * derives the target uid from the developer's checkout (not the Ngramx
     * process uid, so it stays correct even when Ngramx runs as root).
     */
    private function reconcileWorktreeOwnership(string $worktreePath, OutputFormatter $formatter): void
    {
        $result = $this->ownershipReconciler->reconcile($worktreePath);

        if ($result->isReconciled()) {
            $formatter->info('Reconciled worktree file ownership to the developer user');

            return;
        }

        if ($result->isFailed()) {
            // Non-fatal: the environment is still usable. Point at the manual fix
            // so a later "Permission denied" writing storage is easy to resolve.
            $formatter->warning(
                'Could not normalise worktree file ownership. If you hit a "Permission denied" '
                . "writing storage/logs, run:\n  sudo chown -R {$result->uid}:{$result->gid} {$worktreePath}"
            );
        }
    }

    /**
     * Ensure the worktree has its own ngramx.yml.
     *
     * A worktree lives inside the parent repo, so config resolution walks *up*
     * the tree. If the branch being reviewed predates the project adopting
     * Ngramx (or otherwise doesn't track ngramx.yml), the worktree has no
     * config of its own and resolution escapes into the parent repo. `up` then
     * runs against the parent's compose file and silently drops the worktree's
     * generated override, so the stack comes up with the base file's hard-coded
     * container names and collides with the main checkout (or other worktrees).
     *
     * Seeding ngramx.yml from the parent — exactly like we seed .env — keeps the
     * worktree self-contained so its namespaced override is always applied. When
     * the branch *does* track ngramx.yml we leave it untouched.
     */
    private function seedWorktreeConfig(string $repositoryPath, string $worktreePath, OutputFormatter $formatter): void
    {
        $worktreeConfig = $worktreePath . '/ngramx.yml';
        if (file_exists($worktreeConfig)) {
            return;
        }

        $parentConfig = $repositoryPath . '/ngramx.yml';
        if (!file_exists($parentConfig)) {
            return;
        }

        if (@copy($parentConfig, $worktreeConfig)) {
            $formatter->info('Seeded ngramx.yml from parent checkout (branch does not track it)');
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
            $formatter->info("Open it manually with: cursor --new-window $worktreePath");
            return;
        }

        $cursorBinary = trim($probe->getOutput());
        if ($cursorBinary === '') {
            $formatter->warning('The `cursor` command was not found on your PATH.');
            $formatter->info("Open it manually with: cursor --new-window $worktreePath");
            return;
        }

        $resolvedWorktreePath = realpath($worktreePath) ?: $worktreePath;
        $manualCommand = 'cursor --new-window ' . $resolvedWorktreePath;
        $ipcHooks = CursorIpcHookResolver::discoverCandidates();
        $attemptHooks = $ipcHooks !== [] ? $ipcHooks : [null];
        $lastOutput = '';

        try {
            foreach ($attemptHooks as $ipcHook) {
                $process = new Process([$cursorBinary, '--new-window', $resolvedWorktreePath]);
                $process->setTimeout(30);

                $runtimeEnv = [];
                if (is_string($ipcHook)) {
                    $runtimeEnv['VSCODE_IPC_HOOK_CLI'] = $ipcHook;
                } elseif (getenv('VSCODE_IPC_HOOK_CLI') !== false) {
                    $runtimeEnv['VSCODE_IPC_HOOK_CLI'] = false;
                }

                $process->run(null, $runtimeEnv);
                $lastOutput = trim($process->getErrorOutput() . "\n" . $process->getOutput());

                if ($process->isSuccessful()) {
                    $formatter->info("Opening Cursor: $manualCommand");
                    return;
                }

                if (!CursorIpcHookResolver::isIpcConnectionFailure($lastOutput)) {
                    break;
                }
            }

            $formatter->warning('Could not open Cursor automatically.');
            $formatter->info("Open it manually with: $manualCommand");
            if ($lastOutput !== '') {
                $formatter->info($lastOutput);
            }
        } catch (\Throwable) {
            $formatter->warning('Could not open Cursor automatically.');
            $formatter->info("Open it manually with: $manualCommand");
        }
    }

    /**
     * Look for completion.json (preferred) or completion.md (legacy fallback) in
     * .ngramx/tickets/<ticket>/ and display the completion info.
     */
    private function displayCompletionUrls(string $repositoryPath, string $ticketNumber, OutputFormatter $formatter, ?string $environmentUrl = null): void
    {
        $ticketDir = $this->findTicketDirectory($repositoryPath, $ticketNumber);

        if ($ticketDir === null) {
            return;
        }

        $jsonFile = $this->findFileInDirectory($ticketDir, 'completion.json');
        if ($jsonFile !== null) {
            $this->displayCompletionJson($jsonFile, $formatter, $environmentUrl);

            return;
        }

        $mdFile = $this->findFileInDirectory($ticketDir, 'completion.md');
        if ($mdFile !== null) {
            $contents = file_get_contents($mdFile);
            if ($contents !== false) {
                $urls = $this->parseLegacyCompletionMd($contents);
                if ($urls !== []) {
                    $formatter->getOutput()->writeln('');
                    foreach ($urls as $label => $url) {
                        // Legacy md mixes app deep-links with external links (PR,
                        // Linear) under one flat list, so we can't safely rewrite
                        // hosts here — only the JSON `test_urls` field is
                        // unambiguously application deep-links.
                        $formatter->url($label, $url);
                    }
                }
            }
        }
    }

    /**
     * Rewrite a completion deep-link onto the environment this command is
     * operating against (worktree or main checkout) so the printed link opens
     * the correct host/port. Falls back to the stored URL when no environment
     * URL is known. See {@see CompletionUrlRewriter} for the rules.
     */
    private function localiseTestUrl(string $url, ?string $environmentUrl): string
    {
        if ($environmentUrl === null || $environmentUrl === '') {
            return $url;
        }

        return CompletionUrlRewriter::rewrite($url, $environmentUrl);
    }

    private const COMPLETION_RULE_WIDTH = 78;
    private const COMPLETION_CONTENT_WIDTH = 74;
    private const COLOR_DIM = '#6B7B8D';
    private const COLOR_STALE_GREEN = '#4A7A4A';

    /**
     * Parse and display the full completion.json with title, description,
     * test plan (with active/stale status), and links.
     */
    private function displayCompletionJson(string $filePath, OutputFormatter $formatter, ?string $environmentUrl = null): void
    {
        $contents = file_get_contents($filePath);
        if ($contents === false) {
            return;
        }

        $data = json_decode($contents, true);
        if (!is_array($data)) {
            return;
        }

        $output = $formatter->getOutput();
        $teal = OutputFormatter::COLOR_TEAL;
        $smoke = OutputFormatter::COLOR_SMOKE;
        $purple = OutputFormatter::COLOR_PURPLE;
        $dim = self::COLOR_DIM;
        $rule = str_repeat('━', self::COMPLETION_RULE_WIDTH);

        $output->writeln('');
        $output->writeln("<fg={$purple}>{$rule}</>");

        if (isset($data['title']) && is_string($data['title'])) {
            foreach ($this->wrapText($data['title'], self::COMPLETION_CONTENT_WIDTH) as $line) {
                $output->writeln("  <fg={$teal};options=bold>{$line}</>");
            }
        }

        if (isset($data['description']) && is_string($data['description'])) {
            foreach ($this->wrapText($data['description'], self::COMPLETION_CONTENT_WIDTH) as $line) {
                $output->writeln("  <fg={$smoke}>{$line}</>");
            }
        }

        $output->writeln("<fg={$purple}>{$rule}</>");

        if (isset($data['test_plan']) && is_array($data['test_plan']) && $data['test_plan'] !== []) {
            $output->writeln('');
            $output->writeln("<fg={$teal}>▸ How to Test</>");

            $blocks = array_values(array_filter($data['test_plan'], 'is_array'));
            $lastIdx = count($blocks) - 1;

            foreach ($blocks as $i => $block) {
                $isStale = ($block['status'] ?? 'active') === 'stale';
                $isLast = ($i === $lastIdx);
                $branch = $isLast ? '└─' : '├─';

                $output->writeln('');

                if ($isStale) {
                    $desc = $block['description'] ?? '';
                    $output->writeln("<fg={$purple}>{$branch}</> <fg=" . self::COLOR_STALE_GREEN . ">✓</> \e[9m<fg={$dim}>{$desc}</>\e[0m");
                } else {
                    $desc = $block['description'] ?? '';
                    $output->writeln("<fg={$purple}>{$branch}</> <fg={$smoke}>{$desc}</>");

                    $steps = $block['steps'] ?? [];
                    if (is_array($steps)) {
                        $gutter = $isLast ? '   ' : "<fg={$purple}>│</>  ";
                        $lastStepIdx = count($steps) - 1;
                        foreach ($steps as $j => $step) {
                            if (!is_string($step)) {
                                continue;
                            }
                            $stepBranch = ($j === $lastStepIdx) ? '└─' : '├─';
                            $output->writeln("   {$gutter}<fg={$dim}>{$stepBranch}</> <fg={$dim}>{$step}</>");
                        }
                    }
                }
            }
        }

        $output->writeln('');

        if (isset($data['pr_url']) && is_string($data['pr_url'])) {
            $formatter->url('PR', $data['pr_url']);
        }

        if (isset($data['linear_url']) && is_string($data['linear_url'])) {
            $formatter->url('Linear', $data['linear_url']);
        }

        if (isset($data['test_urls']) && is_array($data['test_urls'])) {
            foreach ($data['test_urls'] as $entry) {
                if (is_array($entry) && isset($entry['label'], $entry['url']) && is_string($entry['label']) && is_string($entry['url'])) {
                    $formatter->url($entry['label'], $this->localiseTestUrl($entry['url'], $environmentUrl));
                }
            }
        }

        $output->writeln('');
    }

    /**
     * @return list<string>
     */
    private function wrapText(string $text, int $width): array
    {
        return explode("\n", wordwrap($text, $width, "\n", true));
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
     * Case-insensitive search for a file by name in a directory.
     */
    private function findFileInDirectory(string $directory, string $filename): ?string
    {
        $files = scandir($directory);
        if ($files === false) {
            return null;
        }

        foreach ($files as $file) {
            if (strcasecmp($file, $filename) === 0) {
                return $directory . '/' . $file;
            }
        }

        return null;
    }

    /**
     * Legacy parser for completion.md files (bullet-line format).
     *
     * @return array<string, string> label => URL
     */
    private function parseLegacyCompletionMd(string $contents): array
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
