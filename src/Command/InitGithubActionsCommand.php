<?php

declare(strict_types=1);

namespace Ngramx\Command;

use Ngramx\Output\OutputFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class InitGithubActionsCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('init-github-actions')
            ->setDescription('Create .github/workflows caller files that use the shared Claude automation workflows')
            ->addOption(
                'repo',
                null,
                InputOption::VALUE_REQUIRED,
                'GitHub repository (owner/name) that hosts reusable workflows',
                'gigabyte-software/shared-workflows'
            )
            ->addOption(
                'ref',
                null,
                InputOption::VALUE_REQUIRED,
                'Git ref for reusable workflows (branch, tag, or SHA)',
                'main'
            )
            ->addOption(
                'ci-workflow-name',
                null,
                InputOption::VALUE_REQUIRED,
                'Name of this project\'s primary CI workflow. Used by claude-auto-fix (workflow_run.workflows) and added to the linear-status-sync watch list so the "In Review" move always covers your CI even if it is not one of the common names.',
                'Tests'
            )
            ->addOption(
                'base-branch',
                null,
                InputOption::VALUE_REQUIRED,
                'Default branch (auto-rebase push filter and rebase target)',
                'main'
            )
            ->addOption(
                'php-version',
                null,
                InputOption::VALUE_REQUIRED,
                'PHP version passed to reusable workflows (setup-php)',
                '8.3'
            )
            ->addOption(
                'node-version',
                null,
                InputOption::VALUE_REQUIRED,
                'Node.js version passed to reusable workflows',
                '20'
            )
            ->addOption('no-composer', null, InputOption::VALUE_NONE, 'Set run-composer-install=false on reusable workflow inputs')
            ->addOption('no-npm', null, InputOption::VALUE_NONE, 'Set run-npm-ci=false on reusable workflow inputs')
            ->addOption(
                'risk-low-label',
                null,
                InputOption::VALUE_REQUIRED,
                'Label that marks a PR as low risk (one of two gates for auto-merge)',
                'risk:low'
            )
            ->addOption(
                'size-small-label',
                null,
                InputOption::VALUE_REQUIRED,
                'Label that marks a PR as small (one of two gates for auto-merge)',
                'size:small'
            )
            ->addOption(
                'auto-merge-label',
                null,
                InputOption::VALUE_REQUIRED,
                'Label applied to PRs that pass the auto-merge gate',
                'auto-merge'
            )
            ->addOption(
                'protected-branches',
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated branch names that must NEVER be auto-merged into. Defaults to environment-promotion branches; main/master are intentionally excluded so the small + low-risk gate can flow into them.',
                'prod,production,stage,staging,test,testing'
            )
            ->addOption(
                'merge-method',
                null,
                InputOption::VALUE_REQUIRED,
                'Merge method to use (merge, squash, rebase)',
                'squash'
            )
            ->addOption(
                'linear-in-progress-state-name',
                null,
                InputOption::VALUE_REQUIRED,
                'Linear workflow state name set when CI starts (matched by name within the issue\'s team)',
                'In Progress'
            )
            ->addOption(
                'linear-in-review-state-name',
                null,
                InputOption::VALUE_REQUIRED,
                'Linear workflow state name set when CI passes (matched by name within the issue\'s team)',
                'In Review'
            )
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing workflow files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);

        try {
            $cwd = getcwd();
            if ($cwd === false) {
                throw new \RuntimeException('Failed to get current working directory');
            }

            $githubDir = $cwd . '/.github/workflows';
            if (!is_dir($githubDir) && !mkdir($githubDir, 0755, true)) {
                throw new \RuntimeException("Failed to create directory: $githubDir");
            }

            $sharedRepo = (string) $input->getOption('repo');
            $sharedRef = (string) $input->getOption('ref');
            $ciName = (string) $input->getOption('ci-workflow-name');
            $baseBranch = (string) $input->getOption('base-branch');
            $phpVersion = (string) $input->getOption('php-version');
            $nodeVersion = (string) $input->getOption('node-version');
            $runComposer = $input->getOption('no-composer') ? 'false' : 'true';
            $runNpm = $input->getOption('no-npm') ? 'false' : 'true';
            $riskLowLabel = (string) $input->getOption('risk-low-label');
            $sizeSmallLabel = (string) $input->getOption('size-small-label');
            $autoMergeLabel = (string) $input->getOption('auto-merge-label');
            $protectedBranches = $this->normalizeProtectedBranches((string) $input->getOption('protected-branches'));
            $mergeMethod = (string) $input->getOption('merge-method');
            $linearInProgressStateName = (string) $input->getOption('linear-in-progress-state-name');
            $linearInReviewStateName = (string) $input->getOption('linear-in-review-state-name');
            $force = (bool) $input->getOption('force');

            $allowedMergeMethods = ['merge', 'squash', 'rebase'];
            if (!in_array($mergeMethod, $allowedMergeMethods, true)) {
                throw new \RuntimeException(sprintf(
                    'Invalid --merge-method "%s". Allowed: %s',
                    $mergeMethod,
                    implode(', ', $allowedMergeMethods)
                ));
            }

            $replacements = [
                '{{SHARED_REPO}}' => $sharedRepo,
                '{{SHARED_REF}}' => $sharedRef,
                '{{CI_WORKFLOW_NAME}}' => $ciName,
                '{{BASE_BRANCH}}' => $baseBranch,
                '{{PHP_VERSION}}' => $phpVersion,
                '{{NODE_VERSION}}' => $nodeVersion,
                '{{RUN_COMPOSER}}' => $runComposer,
                '{{RUN_NPM}}' => $runNpm,
                '{{RISK_LOW_LABEL}}' => $riskLowLabel,
                '{{SIZE_SMALL_LABEL}}' => $sizeSmallLabel,
                '{{AUTO_MERGE_LABEL}}' => $autoMergeLabel,
                '{{PROTECTED_BRANCHES}}' => $protectedBranches,
                '{{MERGE_METHOD}}' => $mergeMethod,
                '{{LINEAR_IN_PROGRESS_STATE_NAME}}' => $linearInProgressStateName,
                '{{LINEAR_IN_REVIEW_STATE_NAME}}' => $linearInReviewStateName,
            ];

            $formatter->welcome('Init GitHub Actions (shared workflows)');
            $formatter->section('Writing caller workflows');

            $written = [];
            foreach ($this->workflowSpecs() as $spec) {
                $dest = $githubDir . '/' . $spec['filename'];
                if (file_exists($dest) && !$force) {
                    $formatter->warning("⚠ Skipped {$spec['filename']} (exists; use --force to overwrite)");

                    continue;
                }

                $templatePath = $this->getTemplatesRoot() . '/github-actions/' . $spec['template'];
                if (!is_file($templatePath)) {
                    throw new \RuntimeException("Missing template: $templatePath");
                }

                $content = file_get_contents($templatePath);
                if ($content === false) {
                    throw new \RuntimeException("Failed to read template: $templatePath");
                }

                $content = strtr($content, $replacements);
                if (file_put_contents($dest, $content) === false) {
                    throw new \RuntimeException("Failed to write: $dest");
                }
                $written[] = $spec['filename'];
                $formatter->info("✓ Wrote .github/workflows/{$spec['filename']}");
            }

            if ($written === []) {
                $formatter->info('No files were written.');
            } else {
                $formatter->section('Required organisation secrets');
                $formatter->info('These workflows authenticate using organisation-level secrets (Settings → Secrets and variables → Actions, at the org level). Set them once for the whole org so every repo inherits them — the workflows cannot log in without these:');
                $formatter->info('  • LINEAR_API_KEY — Linear API key used to log in and update issue status');
                $formatter->info('  • CLAUDE_FIXER_APP_ID, CLAUDE_FIXER_APP_PRIVATE_KEY, ANTHROPIC_API_KEY — Claude automation');
                $formatter->info('  • COMPOSER_GITHUB_TOKEN — optional, for private Composer packages');
                $formatter->info('The Linear status sync resolves "' . $linearInProgressStateName . '"/"' . $linearInReviewStateName . '" state UUIDs by name within each issue\'s team, so no per-team state-ID secrets are needed.');
                $formatter->warning('⚠ Without LINEAR_API_KEY set at the org (or repo) level, the Linear status sync will skip silently.');

                $formatter->section('Next steps');
                $formatter->info('1. Set the organisation secrets listed above (or per-repo if you are not using org-wide secrets)');
                $formatter->info('2. Linear status sync moves issues to "' . $linearInProgressStateName . '" from the generic pull_request trigger (no workflow-name matching needed). For the "' . $linearInReviewStateName . '" move it watches a broad list of common CI workflow names plus --ci-workflow-name (currently: ' . $ciName . '); if your CI workflow has an unusual name and is not in that list, add its name to workflows: in .github/workflows/linear-status-sync.yml.');
                $formatter->info('3. Pin --ref to a tag or SHA in production rather than a moving branch');
                $formatter->info('4. See shared repo README: https://github.com/' . $sharedRepo);
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $formatter->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * @return list<array{template: string, filename: string}>
     */
    private function workflowSpecs(): array
    {
        return [
            ['template' => 'claude-auto-fix.caller.yml.template', 'filename' => 'claude-auto-fix.yml'],
            ['template' => 'claude-auto-rebase.caller.yml.template', 'filename' => 'claude-auto-rebase.yml'],
            ['template' => 'claude-fix-review-comments.caller.yml.template', 'filename' => 'claude-fix-review-comments.yml'],
            ['template' => 'auto-merge.caller.yml.template', 'filename' => 'auto-merge.yml'],
            ['template' => 'linear-status-sync.caller.yml.template', 'filename' => 'linear-status-sync.yml'],
        ];
    }

    /**
     * Normalize a comma-separated list of branch names: trim whitespace, drop empty entries, dedupe.
     */
    private function normalizeProtectedBranches(string $raw): string
    {
        $parts = array_filter(array_map('trim', explode(',', $raw)), static fn (string $name): bool => $name !== '');

        return implode(',', array_values(array_unique($parts)));
    }

    private function getTemplatesRoot(): string
    {
        if (\Phar::running() !== '') {
            return \Phar::running() . '/templates';
        }

        return dirname(__DIR__, 2) . '/templates';
    }
}
