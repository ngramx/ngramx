<?php

declare(strict_types=1);

namespace Ngramx\Command;

use Exception;
use Ngramx\Config\Exception\ConfigException;
use Ngramx\Output\OutputFormatter;
use Ngramx\Worktree\WorktreeIdentity;
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
            ->addArgument('ticket', InputArgument::REQUIRED, 'The ticket to work on: a bare number ("2345", prefixed with the configured default team), or a full reference ("gig-2345" / "gig2345")')
            ->addOption('quick', null, InputOption::VALUE_NONE, 'Use the "clear" command instead of "fresh" — skips the database reset. Only safe on branches with no schema or seed changes.')
            ->addOption('cursor', null, InputOption::VALUE_NONE, 'Open the worktree in a new Cursor window once it is ready');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);
        $ticketArgument = $input->getArgument('ticket');
        $rawTicket = is_string($ticketArgument) ? trim($ticketArgument) : '';

        if ($rawTicket === '') {
            $formatter->error('A ticket identifier is required, e.g. `ngramx worktree 2345` or `ngramx worktree gig-2345`.');
            return Command::FAILURE;
        }

        try {
            $configPath = $this->configLoader->findConfigFile();
            $config = $this->configLoader->load($configPath);
            $repositoryPath = dirname($configPath);

            $ticketSlug = WorktreeIdentity::normalizeTicket($rawTicket, $config->defaultTeam);

            $formatter->section("Preparing worktree for ticket: $ticketSlug");

            $formatter->info('Fetching latest changes from origin...');
            if (!$this->gitRepositoryService->fetchFromOrigin($repositoryPath)) {
                $formatter->error('Failed to fetch from origin. Make sure you have git configured on your host machine and have access to the repository.');
                return Command::FAILURE;
            }

            $formatter->info('Searching remote branches for the ticket...');
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

            $selectedBranch = $this->gitRepositoryService->selectBranch(
                $repositoryPath,
                $branchNames,
                $input,
                $output,
                fn (string $message) => $formatter->info($message),
                fn (string $message) => $formatter->warning($message),
                fn (string $branch) => str_starts_with($branch, $ticketSlug)
            );

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
     * Search remote branches for the ticket, trying the most specific spelling
     * first: the canonical slug ("gig-2345"), then the hyphen-less variant
     * ("gig2345"), then the bare number ("2345"). The first spelling that
     * matches anything wins, so a branch named with either convention is found
     * without the bare-number fallback dragging in unrelated tickets.
     *
     * @return array<string> Branch names (without origin/ prefix)
     */
    private function findTicketBranches(string $repositoryPath, string $rawTicket, string $ticketSlug): array
    {
        $candidates = [$ticketSlug, str_replace('-', '', $ticketSlug)];

        // For a bare-number invocation the user's input *is* the identifier the
        // ticket's branches most likely contain (e.g. cursor/2345-fix-thing).
        if (preg_match('/^\d+$/', $rawTicket) === 1) {
            $candidates[] = $rawTicket;
        }

        foreach (array_unique($candidates) as $candidate) {
            $branches = $this->gitRepositoryService->findBranchesContaining($repositoryPath, $candidate);
            if ($branches !== []) {
                return $branches;
            }
        }

        return [];
    }
}
