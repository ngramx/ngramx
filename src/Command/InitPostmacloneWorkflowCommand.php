<?php

declare(strict_types=1);

namespace Ngramx\Command;

use Ngramx\Output\OutputFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class InitPostmacloneWorkflowCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('init-postmaclone-workflow')
            ->setDescription('Create a scheduled GitHub Actions workflow for nightly postmaclone produce')
            ->addOption(
                'config',
                null,
                InputOption::VALUE_REQUIRED,
                'Factory postmaclone.yml path passed to produce',
                'postmaclone.yml'
            )
            ->addOption(
                'dataset',
                null,
                InputOption::VALUE_REQUIRED,
                'Produce a single dataset (omit to use --all)'
            )
            ->addOption(
                'cron',
                null,
                InputOption::VALUE_REQUIRED,
                'Cron schedule (UTC)',
                '0 3 * * *'
            )
            ->addOption(
                'php-version',
                null,
                InputOption::VALUE_REQUIRED,
                'PHP version for the runner',
                '8.3'
            )
            ->addOption(
                'ngramx-download-url',
                null,
                InputOption::VALUE_REQUIRED,
                'URL to download ngramx.phar',
                'https://github.com/ngramx/ngramx/releases/latest/download/ngramx.phar'
            )
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite an existing workflow file');
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
                throw new \RuntimeException("Failed to create directory: {$githubDir}");
            }

            $dataset = $input->getOption('dataset');
            $produceArgs = is_string($dataset) && $dataset !== ''
                ? '--dataset ' . escapeshellarg($dataset)
                : '--all';

            $replacements = [
                '{{CRON}}' => (string) $input->getOption('cron'),
                '{{PHP_VERSION}}' => (string) $input->getOption('php-version'),
                '{{NGRAMX_DOWNLOAD_URL}}' => (string) $input->getOption('ngramx-download-url'),
                '{{PRODUCE_ARGS}}' => $produceArgs,
                '{{CONFIG_PATH}}' => (string) $input->getOption('config'),
            ];

            $dest = $githubDir . '/postmaclone-produce.yml';
            $force = (bool) $input->getOption('force');
            if (file_exists($dest) && !$force) {
                $formatter->warning('Skipped postmaclone-produce.yml (exists; use --force to overwrite)');

                return Command::SUCCESS;
            }

            $templatePath = $this->getTemplatesRoot() . '/github-actions/postmaclone-produce.caller.yml.template';
            if (!is_file($templatePath)) {
                throw new \RuntimeException("Missing template: {$templatePath}");
            }

            $content = file_get_contents($templatePath);
            if ($content === false) {
                throw new \RuntimeException("Failed to read template: {$templatePath}");
            }

            $content = strtr($content, $replacements);
            if (file_put_contents($dest, $content) === false) {
                throw new \RuntimeException("Failed to write: {$dest}");
            }

            $formatter->welcome('Init Postmaclone workflow');
            $formatter->info('✓ Wrote .github/workflows/postmaclone-produce.yml');
            $formatter->section('Required repository secrets');
            $formatter->info('  • OP_SERVICE_ACCOUNT_TOKEN — 1Password service account with read **and write** on Tech Team Vault');
            $formatter->info('  • POSTMACLONE_REMOTE_URL — optional scratch DB URL override for target.provider: remote');
            $formatter->section('Factory config');
            $formatter->info('Define engines.{postgres|mysql}.scratch/anon.credentials once (1Password server/port/user/pass).');
            $formatter->info('Each dataset only needs target.remote.database and shared.database for its engine.');
            $formatter->info('shared.password_rotation_days defaults to 7; produce rotates the DB password and updates 1Password when due.');
            $formatter->info('See postmaclone.example.yml and README Post Maclone → Large DBs.');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $formatter->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    private function getTemplatesRoot(): string
    {
        if (\Phar::running() !== '') {
            return \Phar::running() . '/templates';
        }

        return dirname(__DIR__, 2) . '/templates';
    }
}
