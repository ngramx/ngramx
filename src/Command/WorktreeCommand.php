<?php

declare(strict_types=1);

namespace Ngramx\Command;

use Exception;
use Ngramx\Config\Exception\ConfigException;
use Ngramx\Config\Schema\NgramxConfig;
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
            ->addArgument('ticket', InputArgument::OPTIONAL, 'The ticket to work on: a bare number ("2345", prefixed with the configured default team), or a full reference ("gig-2345" / "gig2345"). Omit on a feature branch to move the current branch into a worktree. Optional with --cleanup, where it targets a single worktree (omit it to clean up every worktree).')
            ->addOption('quick', null, InputOption::VALUE_NONE, 'Use the "clear" command instead of "fresh" — skips the database reset. Only safe on branches with no schema or seed changes.')
            ->addOption('cursor', 'c', InputOption::VALUE_NONE, 'Open the worktree in a new Cursor window once it is ready')
            ->addOption('cleanup', null, InputOption::VALUE_NONE, 'Stop and remove worktree(s) + parallel environments. Targets one ticket when given, or every worktree when no ticket is provided.');
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

            if ((bool) $input->getOption('cleanup')) {
                if ($rawTicket === '') {
                    return $this->runWorktreeCleanupAll($output, $formatter, $repositoryPath);
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
                    fn (string $branch) => str_starts_with($branch, $ticketSlug)
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
}
