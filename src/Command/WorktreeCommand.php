<?php

declare(strict_types=1);

namespace Ngramx\Command;

use Exception;
use Ngramx\Config\Exception\ConfigException;
use Ngramx\Config\LockFile;
use Ngramx\Config\Schema\NgramxConfig;
use Ngramx\Http\UrlPortOffset;
use Ngramx\Output\OutputFormatter;
use Ngramx\Worktree\WorktreeIdentity;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `ngramx worktree <ticket>` — start (or continue) your own work on a ticket in
 * an isolated git worktree with its own parallel dev environment.
 *
 * This is the "author" counterpart to `ngramx review`: `review` checks out
 * someone else's branch, while `worktree` finds your branch for the ticket (or
 * creates a fresh `{team}-{number}` branch when none exists yet) and brings up
 * an environment for it. All of the heavy lifting — worktree creation,
 * dependency priming, environment startup — is shared with ReviewCommand.
 */
class WorktreeCommand extends ReviewCommand
{
    protected function configure(): void
    {
        $this
            ->setName('worktree')
            ->setDescription('Create (or reuse) a git worktree with an isolated dev environment for working on a ticket')
            ->addArgument('ticket', InputArgument::OPTIONAL, 'The ticket to work on: a bare number ("2345", prefixed with the configured default team), or a full reference ("gig-2345" / "gig2345"). Omit on a feature branch to move the current branch into a worktree. With --cleanup, a bare number is treated as the 1-based index from `ngramx worktree --list` (use a ticket reference like "gig-2345" to target by ticket instead).')
            ->addOption('quick', null, InputOption::VALUE_NONE, 'Use the "clear" command instead of "fresh" — skips the database reset. Only safe on branches with no schema or seed changes.')
            ->addOption('cursor', 'c', InputOption::VALUE_NONE, 'Open the worktree in a new Cursor window once it is ready')
            ->addOption('cleanup', null, InputOption::VALUE_NONE, 'Stop and remove worktree(s) + parallel environments. Targets one worktree when a ticket or list index is given, or every worktree when no argument is provided.')
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'List every worktree under .ngramx/worktrees/ with its branch, running state and URL.')
            ->addOption('no-host-mapping', null, InputOption::VALUE_NONE, 'Do not expose container ports to the host. Use on shared or headless machines where host ports may already be taken; reach the app over the Docker network instead.')
            ->addOption('branch', 'b', InputOption::VALUE_REQUIRED, 'Use this exact branch instead of searching for one matching the ticket. Created from the current HEAD if it does not exist yet.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);
        $ticketArgument = $input->getArgument('ticket');
        $rawTicket = is_string($ticketArgument) ? trim($ticketArgument) : '';

        try {
            $configPath = $this->configLoader->findConfigFile();
            $config = $this->configLoader->load($configPath);
            $repositoryPath = dirname($configPath);

            if ((bool) $input->getOption('list')) {
                return $this->runWorktreeStatus($output, $formatter, $config, $repositoryPath);
            }

            if ((bool) $input->getOption('cleanup')) {
                if ($rawTicket === '') {
                    return $this->runWorktreeCleanupAll($output, $formatter, $repositoryPath);
                }

                // A bare positive integer that falls within the worktree list
                // range is treated as a 1-based index from `ngramx worktree
                // --list`, so the developer can clean up by number without
                // typing the full ticket slug. Out-of-range integers and
                // non-numeric arguments fall through to ticket-based cleanup.
                $worktrees = $this->listWorktreeDirectories($repositoryPath);
                $index = $this->parseListIndex($rawTicket, count($worktrees));
                if ($index !== null) {
                    return $this->runWorktreeCleanupByIndex(
                        $output,
                        $formatter,
                        $repositoryPath,
                        $worktrees,
                        $index,
                    );
                }

                $ticketSlug = WorktreeIdentity::normalizeTicket($rawTicket, $config->defaultTeam);

                return $this->runWorktreeCleanup($output, $formatter, $repositoryPath, $ticketSlug);
            }

            if ($rawTicket === '') {
                return $this->runWorktreeFromCurrentBranch(
                    $input,
                    $output,
                    $formatter,
                    $config,
                    $repositoryPath
                );
            }

            $ticketSlug = WorktreeIdentity::normalizeTicket($rawTicket, $config->defaultTeam);

            $formatter->section("Preparing worktree for ticket: $ticketSlug");

            $formatter->info('Fetching latest changes from origin...');
            if (!$this->gitRepositoryService->fetchFromOrigin($repositoryPath)) {
                $formatter->error('Failed to fetch from origin. Make sure you have git configured on your host machine and have access to the repository.');
                return Command::FAILURE;
            }

            // An explicit --branch wins outright: no search, no "most recent"
            // heuristic. Automation that already knows which branch it wants
            // otherwise has no way to say so.
            $pinnedBranch = $input->getOption('branch');
            if (is_string($pinnedBranch) && trim($pinnedBranch) !== '') {
                $pinnedBranch = trim($pinnedBranch);

                $branchIsKnown = $this->gitRepositoryService->localBranchExists($repositoryPath, $pinnedBranch)
                    || $this->gitRepositoryService->findBranchesContaining($repositoryPath, $pinnedBranch) !== [];

                $formatter->info($branchIsKnown
                    ? "Using the requested branch '$pinnedBranch'."
                    : "Requested branch '$pinnedBranch' does not exist yet — it will be created.");

                return $this->runWorktreeReview(
                    $input,
                    $output,
                    $formatter,
                    $config,
                    $repositoryPath,
                    $pinnedBranch,
                    $ticketSlug,
                    createNewBranch: !$branchIsKnown
                );
            }

            $formatter->info('Searching branches for the ticket...');
            $branchNames = $this->findTicketBranches($repositoryPath, $rawTicket, $ticketSlug);

            if ($branchNames === []) {
                // A previous `ngramx worktree` run may have created the branch
                // locally without it ever being pushed; reuse it rather than
                // failing to re-create a branch that already exists.
                $createNewBranch = !$this->gitRepositoryService->localBranchExists($repositoryPath, $ticketSlug);

                $formatter->info($createNewBranch
                    ? "No existing branches found — a new branch '$ticketSlug' will be created."
                    : "No remote branches found — reusing the local branch '$ticketSlug'.");

                return $this->runWorktreeReview(
                    $input,
                    $output,
                    $formatter,
                    $config,
                    $repositoryPath,
                    $ticketSlug,
                    $ticketSlug,
                    createNewBranch: $createNewBranch
                );
            }

            try {
                /** @var list<string> $matchingBranches */
                $matchingBranches = array_values($branchNames);

                $selectedBranch = $this->gitRepositoryService->selectBranchForWorktree(
                    $repositoryPath,
                    $matchingBranches,
                    $input,
                    $output,
                    fn (string $message) => $formatter->info($message),
                    fn (string $message) => $formatter->warning($message),
                    fn (string $branch) => str_starts_with($branch, $ticketSlug),
                    // A branch sitting in this ticket's own worktree is reusable,
                    // not a conflict — otherwise a half-finished run is unrepeatable.
                    $this->worktreePathFor($repositoryPath, $ticketSlug)
                );
            } catch (RuntimeException $e) {
                $formatter->error($e->getMessage());

                return Command::FAILURE;
            }

            return $this->runWorktreeReview(
                $input,
                $output,
                $formatter,
                $config,
                $repositoryPath,
                $selectedBranch,
                $ticketSlug
            );
        } catch (ConfigException $e) {
            $formatter->error("Configuration error: {$e->getMessage()}");
            return Command::FAILURE;
        } catch (Exception $e) {
            $formatter->error("Error: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    /**
     * Search remote and local branches for the ticket, trying the most specific
     * spelling first: the canonical slug ("gig-2345"), then the hyphen-less variant
     * ("gig2345"), then the bare number ("2345"), then the raw user input when it
     * looks like a full branch name. The first spelling that matches anything
     * wins, so a branch named with either convention is found without the
     * bare-number fallback dragging in unrelated tickets.
     *
     * @return array<string> Branch names (without origin/ prefix)
     */
    private function findTicketBranches(string $repositoryPath, string $rawTicket, string $ticketSlug): array
    {
        $prefixMatches = $this->gitRepositoryService->findBranchesForTicketPrefix($repositoryPath, $ticketSlug);
        if ($prefixMatches !== []) {
            return $prefixMatches;
        }

        $normalisedRaw = strtolower(trim($rawTicket));
        if ($normalisedRaw !== $ticketSlug && $this->gitRepositoryService->localBranchExists($repositoryPath, $normalisedRaw)) {
            return [$normalisedRaw];
        }

        $candidates = [str_replace('-', '', $ticketSlug)];

        if (preg_match('/^\d+$/', $rawTicket) === 1) {
            $candidates[] = $rawTicket;
        }

        foreach (array_unique($candidates) as $candidate) {
            $branches = $this->gitRepositoryService->findBranchesContaining($repositoryPath, $candidate);
            if ($branches !== []) {
                return $branches;
            }
        }

        foreach (array_unique($candidates) as $candidate) {
            $branches = $this->gitRepositoryService->findLocalBranchesContaining($repositoryPath, $candidate);
            if ($branches !== []) {
                return $branches;
            }
        }

        return [];
    }

    /**
     * Move the currently checked-out feature branch into a worktree, freeing the
     * main checkout to return to the integration branch.
     */
    private function runWorktreeFromCurrentBranch(
        InputInterface $input,
        OutputInterface $output,
        OutputFormatter $formatter,
        NgramxConfig $config,
        string $repositoryPath
    ): int {
        $currentBranch = $this->gitRepositoryService->getCurrentBranch($repositoryPath);
        if ($currentBranch === null) {
            $formatter->error('Could not determine the current branch. Check out a feature branch and try again.');
            return Command::FAILURE;
        }

        if ($this->gitRepositoryService->isIntegrationBranch($currentBranch)) {
            $formatter->warning(
                "You're on the {$currentBranch} branch. To open up a worktree, without specifying a ticket, switch to a feature branch first."
            );
            return Command::SUCCESS;
        }

        $featureBranch = $currentBranch;
        $ticketSlug = WorktreeIdentity::deriveTicketSlug($featureBranch, $featureBranch);

        $formatter->section("Preparing worktree for current branch: $featureBranch");

        $didStash = false;
        if ($this->gitRepositoryService->hasUncommittedChanges($repositoryPath)) {
            $formatter->info('Stashing uncommitted changes...');
            if (!$this->gitRepositoryService->stashPush($repositoryPath, "ngramx worktree: {$featureBranch}")) {
                $formatter->error('Failed to stash uncommitted changes.');
                return Command::FAILURE;
            }
            $didStash = true;
        }

        $integrationBranch = $this->gitRepositoryService->resolveDefaultIntegrationBranch($repositoryPath);
        $formatter->info("Switching main checkout to {$integrationBranch}...");
        if (!$this->gitRepositoryService->checkoutLocalBranch($repositoryPath, $integrationBranch)) {
            $message = "Failed to switch the main checkout to '{$integrationBranch}'.";
            $details = trim($this->gitRepositoryService->lastCheckoutError());
            if ($details !== '') {
                $message .= "\n\n" . OutputFormatter::escape($details);
            }
            $formatter->error($message);

            if ($didStash) {
                $formatter->info('Restoring stashed changes in the main checkout...');
                $this->gitRepositoryService->stashPop($repositoryPath);
            }

            return Command::FAILURE;
        }

        return $this->runWorktreeReview(
            $input,
            $output,
            $formatter,
            $config,
            $repositoryPath,
            $featureBranch,
            $ticketSlug,
            popStash: $didStash
        );
    }

    /**
     * List every worktree under .ngramx/worktrees/ with a 1-based index,
     * its checked-out branch, whether its environment is running, and the
     * URL it was brought up on. The indices are what `--cleanup <n>` uses.
     */
    private function runWorktreeStatus(
        OutputInterface $output,
        OutputFormatter $formatter,
        NgramxConfig $config,
        string $repositoryPath
    ): int {
        $worktrees = $this->listWorktreeDirectories($repositoryPath);

        if ($worktrees === []) {
            $formatter->info('No worktrees found under .ngramx/worktrees/.');
            return Command::SUCCESS;
        }

        // Sort alphabetically so the numbering is stable across runs.
        sort($worktrees);

        $branchMap = $this->gitRepositoryService->listWorktreeBranches($repositoryPath);
        $appUrl = $config->docker->appUrl;

        $formatter->section('Active worktrees (' . count($worktrees) . ')');

        $rows = [];
        foreach ($worktrees as $i => $worktreePath) {
            $folder = basename($worktreePath);
            $branch = $branchMap[$worktreePath] ?? '—';
            $running = $this->isWorktreeRunning($worktreePath, $config, $folder);
            $url = $this->worktreeUrl($worktreePath, $appUrl);

            $rows[] = sprintf(
                '  <fg=yellow>%d</>  %-40s  %-30s  %-9s  %s',
                $i + 1,
                $folder,
                $branch,
                $running ? 'running' : 'stopped',
                $url ?? '—',
            );
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '  <fg=#D2DCE5>%-3s  %-40s  %-30s  %-9s  %s</>',
            '#',
            'worktree',
            'branch',
            'status',
            'url',
        ));
        foreach ($rows as $row) {
            $output->writeln($row);
        }
        $output->writeln('');
        $formatter->info('Clean up by index: ngramx worktree --cleanup <#>, or by ticket: ngramx worktree --cleanup <ticket>');

        return Command::SUCCESS;
    }

    /**
     * Tear down a single worktree identified by its 1-based position in the
     * sorted directory listing (the same order `--list` displays).
     *
     * @param list<string> $worktrees
     */
    private function runWorktreeCleanupByIndex(
        OutputInterface $output,
        OutputFormatter $formatter,
        string $repositoryPath,
        array $worktrees,
        int $index
    ): int {
        sort($worktrees);

        $worktreePath = $worktrees[$index - 1] ?? null;
        if ($worktreePath === null) {
            $formatter->error("No worktree at index $index. Run `ngramx worktree --list` to see the available indices.");
            return Command::FAILURE;
        }

        $formatter->section('Cleaning up worktree #' . $index . ': ' . basename($worktreePath));

        if (!$this->teardownWorktree($output, $formatter, $repositoryPath, $worktreePath)) {
            return Command::FAILURE;
        }

        $formatter->success('✓ Removed worktree #' . $index . ' (' . basename($worktreePath) . ')');
        $output->writeln('');

        return Command::SUCCESS;
    }

    /**
     * Parse a bare positive integer as a 1-based list index, returning null
     * when the argument is not a number or is outside the list range.
     */
    private function parseListIndex(string $rawTicket, int $worktreeCount): ?int
    {
        if ($worktreeCount === 0 || preg_match('/^[1-9]\d*$/', $rawTicket) !== 1) {
            return null;
        }

        $index = (int) $rawTicket;

        return $index >= 1 && $index <= $worktreeCount ? $index : null;
    }

    /**
     * Whether the worktree's environment appears to be running. Probes the
     * primary service container via Docker Compose so a stale lock file
     * (left behind by a crash or manual `docker compose down`) does not
     * produce a false "running".
     */
    private function isWorktreeRunning(string $worktreePath, NgramxConfig $config, string $folder): bool
    {
        if (!file_exists($worktreePath . '/.ngramx.lock')) {
            return false;
        }

        $namespace = WorktreeIdentity::namespaceFor($folder);

        return $this->dockerCompose->isServiceRunning(
            $config->docker->composeFile,
            $config->docker->primaryService,
            $namespace,
        );
    }

    /**
     * Resolve the URL the worktree was brought up on, from its lock file's
     * port offset applied to the app's configured URL. Returns null when
     * there is no lock file.
     */
    private function worktreeUrl(string $worktreePath, string $appUrl): ?string
    {
        $lock = new LockFile($worktreePath);
        if (!$lock->exists()) {
            return null;
        }

        $data = $lock->read();
        if ($data === null) {
            return null;
        }

        $url = UrlPortOffset::apply($appUrl, $data->portOffset ?? 0);

        return UrlPortOffset::applyMap($url, $data->portMap);
    }
}
