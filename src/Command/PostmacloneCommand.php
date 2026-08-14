<?php

declare(strict_types=1);

namespace Ngramx\Command;

use Ngramx\Config\ConfigLoader;
use Ngramx\Config\Schema\NgramxConfig;
use Ngramx\Config\Schema\Postmaclone\BackupConfig;
use Ngramx\Output\OutputFormatter;
use Ngramx\Postmaclone\Backup\S3Credentials;
use Ngramx\Postmaclone\Exception\PostmacloneException;
use Ngramx\Postmaclone\PostmacloneDoctor;
use Ngramx\Postmaclone\PostmacloneProducer;
use Ngramx\Postmaclone\PostmacloneService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PostmacloneCommand extends Command
{
    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly PostmacloneService $service = new PostmacloneService(),
        private readonly PostmacloneProducer $producer = new PostmacloneProducer(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('postmaclone')
            ->setDescription('Create an ephemeral anonymized database clone for safe bug RCA (Post Maclone)')
            ->addArgument(
                'action',
                InputArgument::OPTIONAL,
                'Optional lifecycle action: down | status | doctor | produce (omit to create a clone)',
                null
            )
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Dump path, connection URL, or s3:// / spaces:// URI')
            ->addOption('sql', null, InputOption::VALUE_NONE, 'Emit anonymization SQL only (requires --from); do not provision a clone')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write --sql output to a file instead of stdout')
            ->addOption('replace', null, InputOption::VALUE_NONE, 'Destroy any existing clone, then create a fresh one')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Resolve config/source and print the plan without provisioning')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Fail on missing tables/columns instead of warning')
            ->addOption('keep-download', null, InputOption::VALUE_NONE, 'Keep downloaded dump files under .ngramx/cache')
            ->addOption('label', null, InputOption::VALUE_REQUIRED, 'Optional label stored in the lock (e.g. ticket id)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'With down: clear local state even if remote destroy fails')
            ->addOption('no-env', null, InputOption::VALUE_NONE, 'Do not patch project .env DB_* keys')
            ->addOption('from-prod', null, InputOption::VALUE_NONE, 'Skip prebuilt; run full download + anonymize pipeline')
            ->addOption('no-prebuilt', null, InputOption::VALUE_NONE, 'Alias for --from-prod')
            ->addOption('all', null, InputOption::VALUE_NONE, 'With produce: process every dataset in factory postmaclone.yml')
            ->addOption('dataset', null, InputOption::VALUE_REQUIRED, 'With produce: process a single dataset name')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'With produce: path to factory postmaclone.yml');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);
        $action = $input->getArgument('action');
        $action = is_string($action) ? strtolower($action) : null;

        if ($action === 'produce') {
            return $this->runProduce($formatter, $input);
        }

        try {
            $configPath = $this->configLoader->findConfigFile();
            $config = $this->configLoader->load($configPath);
        } catch (\Exception $e) {
            $formatter->error("Failed to load configuration: {$e->getMessage()}");

            return Command::FAILURE;
        }

        $projectRoot = dirname($configPath);

        try {
            if ($action === 'down') {
                return $this->runDown($formatter, $config, $projectRoot, (bool) $input->getOption('force'));
            }
            if ($action === 'status') {
                return $this->runStatus($formatter, $projectRoot);
            }
            if ($action === 'doctor') {
                return $this->runDoctor($formatter, $config, $projectRoot, $input);
            }
            if ($action !== null && $action !== '') {
                $formatter->error("Unknown action '{$action}'. Use: ngramx postmaclone [|down|status|doctor|produce]");

                return Command::FAILURE;
            }

            if ((bool) $input->getOption('sql')) {
                return $this->runSql($formatter, $input, $config, $projectRoot);
            }

            if ((bool) $input->getOption('dry-run')) {
                return $this->runDryRun($formatter, $input, $config, $projectRoot);
            }

            return $this->runCreate($formatter, $input, $config, $projectRoot);
        } catch (PostmacloneException $e) {
            $formatter->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    private function runDown(OutputFormatter $formatter, NgramxConfig $config, string $projectRoot, bool $force): int
    {
        $formatter->welcome('Post Maclone teardown');
        $destroyed = $this->service->destroy($config, $projectRoot, $force);
        if (!$destroyed) {
            $formatter->info('Nothing to destroy (no active Post Maclone lock).');

            return Command::SUCCESS;
        }
        $formatter->success('Clone destroyed and .env restored (if a backup existed).');

        return Command::SUCCESS;
    }

    private function runStatus(OutputFormatter $formatter, string $projectRoot): int
    {
        $lock = $this->service->status($projectRoot);
        if ($lock === null) {
            $formatter->info('No active Post Maclone clone.');

            return Command::SUCCESS;
        }

        $formatter->welcome('Post Maclone status');
        $formatter->info("Provider:  {$lock->provider}");
        $formatter->info("Engine:    {$lock->engine}");
        $formatter->info("Created:   {$lock->createdAt}");
        $formatter->info("Expires:   {$lock->expiresAt}");
        $formatter->info("Host:      {$lock->host}:{$lock->port}");
        $formatter->info("Database:  {$lock->database}");
        $formatter->info("User:      {$lock->username}");
        if ($lock->label) {
            $formatter->info("Label:     {$lock->label}");
        }
        $formatter->info('Tear down: ngramx postmaclone down');

        return Command::SUCCESS;
    }

    private function runDoctor(
        OutputFormatter $formatter,
        NgramxConfig $config,
        string $projectRoot,
        InputInterface $input,
    ): int {
        $formatter->welcome('Post Maclone doctor');

        $pm = $config->postmaclone;
        if ($pm === null) {
            $formatter->error('Missing postmaclone: section in ngramx.yml');

            return Command::FAILURE;
        }

        $formatter->info('postmaclone config: present');
        $formatter->info('backup.source: ' . $pm->backup->source);
        if ($pm->backup->path !== null) {
            $formatter->info('backup.path: ' . $pm->backup->path);
        }
        if ($pm->backup->file !== null) {
            $formatter->info('backup.file: ' . $pm->backup->file);
        }

        $from = $input->getOption('from');
        $fromPath = is_string($from) && is_file($from) ? $from : null;
        $doctor = new PostmacloneDoctor();
        $diagnosis = $doctor->diagnose($config, $projectRoot, $fromPath);
        $needsS3 = $diagnosis['needs_s3'];

        if ($needsS3) {
            $formatter->info('--- auth / credentials ---');
        } else {
            $formatter->info('--- source / restore checks ---');
        }

        $sawRestoreHeading = false;
        foreach ($diagnosis['checks'] as $check) {
            if (
                $needsS3
                && !$sawRestoreHeading
                && str_contains(strtolower($check['message']), 'pdo_')
            ) {
                $formatter->info('--- restore checks ---');
                $sawRestoreHeading = true;
            }
            if ($check['ok']) {
                $formatter->success($check['message']);
            } elseif ($check['blocking']) {
                $formatter->error($check['message']);
            } else {
                $formatter->info($check['message']);
            }
        }

        foreach ($diagnosis['next_steps'] as $step) {
            $formatter->info($step);
        }

        if ($diagnosis['suggestions'] !== []) {
            $formatter->info('--- suggested updates ---');
            foreach ($diagnosis['suggestions'] as $line) {
                $formatter->info($line);
            }
        }

        if ($needsS3 && $diagnosis['ok']) {
            $formatter->info('S3 path looks ready — create with: ngramx postmaclone');
        } elseif ($needsS3 && $pm->backup->credentials === null && $pm->prebuilt?->credentials === null) {
            $formatter->info('Add to ngramx.yml under postmaclone.backup (or prebuilt):');
            $formatter->info('  credentials:');
            $formatter->info('    key: "' . S3Credentials::EXAMPLE_KEY_REF . '"');
            $formatter->info('    secret: "' . S3Credentials::EXAMPLE_SECRET_REF . '"');
        } elseif (!$needsS3 && $diagnosis['ok']) {
            $formatter->info('Source looks ready — create with: ngramx postmaclone');
        }

        return $diagnosis['ok'] ? Command::SUCCESS : Command::FAILURE;
    }

    private function runSql(OutputFormatter $formatter, InputInterface $input, NgramxConfig $config, string $projectRoot): int
    {
        $fromOption = $input->getOption('from');
        if (!is_string($fromOption) || $fromOption === '') {
            $formatter->error('--sql requires --from <dump path|connection URL|s3://...>');

            return Command::FAILURE;
        }

        $from = $this->service->resolveFrom($fromOption, $config, $projectRoot);
        if ($from === null) {
            $formatter->error('Invalid --from value');

            return Command::FAILURE;
        }

        if ($from->isConnection()) {
            $formatter->warning('Reading source connection for --sql only (no writes to source).');
        }

        $result = $this->service->emitSql($config, $projectRoot, $from, (bool) $input->getOption('strict'));
        foreach ($result['warnings'] as $warning) {
            $formatter->warning($warning);
        }

        $out = $input->getOption('output');
        if (is_string($out) && $out !== '') {
            if (file_put_contents($out, $result['sql']) === false) {
                $formatter->error("Failed to write {$out}");

                return Command::FAILURE;
            }
            $formatter->success("Wrote anonymization SQL to {$out}");
        } else {
            $formatter->getOutput()->writeln($result['sql']);
        }

        return Command::SUCCESS;
    }

    private function runDryRun(OutputFormatter $formatter, InputInterface $input, NgramxConfig $config, string $projectRoot): int
    {
        $formatter->welcome('Post Maclone dry-run');
        $pm = $config->postmaclone;
        if ($pm === null) {
            $formatter->error('Missing postmaclone: section in ngramx.yml');

            return Command::FAILURE;
        }

        $preferPrebuilt = !(bool) $input->getOption('from-prod') && !(bool) $input->getOption('no-prebuilt');
        $from = $this->service->resolveFrom(
            is_string($input->getOption('from')) ? $input->getOption('from') : null,
            $config,
            $projectRoot,
        );
        $usePrebuilt = $preferPrebuilt && $from === null && $pm->hasPrebuilt();
        $engine = $this->service->resolveEngine($config, $from);
        $mismatch = $this->service->engineMismatchWarning($config);
        if ($mismatch !== null) {
            $formatter->warning($mismatch);
        }

        $formatter->info("Engine: {$engine}");
        $formatter->info('Target provider: ' . $pm->target->provider . " (ttl {$pm->target->ttlHours}h)");
        if ($usePrebuilt) {
            $formatter->info('Source mode: prebuilt (restore-only, skip anonymize)');
            $formatter->info('prebuilt.path: ' . ($pm->prebuilt?->path ?? ''));
            $source = $this->service->buildPrebuiltSource($pm->prebuilt, $projectRoot);
        } else {
            $formatter->info('Source mode: full pipeline (download + anonymize)');
            $formatter->info('Opt-in tables/columns (only these are anonymized):');
            foreach ($pm->tables as $table) {
                $cols = implode(', ', array_keys($table->columns));
                $formatter->info("  - {$table->table}: {$cols}");
            }
            $source = $this->service->buildBackupSource($config, $projectRoot, $engine, $from);
        }
        $probe = $source->probe();
        $formatter->info('Source: ' . ($probe['detail'] ?? 'unknown') . (isset($probe['size']) ? " ({$probe['size']} bytes)" : ''));
        if (!$probe['exists'] && $from?->isPath()) {
            $formatter->warning('Source does not appear to exist.');
        }
        $formatter->info('No clone was provisioned (--dry-run).');

        return Command::SUCCESS;
    }

    private function runCreate(OutputFormatter $formatter, InputInterface $input, NgramxConfig $config, string $projectRoot): int
    {
        $formatter->welcome('Post Maclone');
        $formatter->warning('Anonymization rules are project-owned. Never point this at production for in-place writes.');

        $preferPrebuilt = !(bool) $input->getOption('from-prod') && !(bool) $input->getOption('no-prebuilt');
        $from = $this->service->resolveFrom(
            is_string($input->getOption('from')) ? $input->getOption('from') : null,
            $config,
            $projectRoot,
        );
        if ($from?->isConnection()) {
            $formatter->warning('Source connection will be dumped/read only; the clone is anonymized separately.');
        }
        if ($preferPrebuilt && $from === null && ($config->postmaclone?->hasPrebuilt() ?? false)) {
            $formatter->info('Using published prebuilt artifact (restore-only).');
        }

        $mismatch = $this->service->engineMismatchWarning($config);
        if ($mismatch !== null) {
            $formatter->warning($mismatch);
        }

        $result = $this->service->create(
            config: $config,
            projectRoot: $projectRoot,
            from: $from,
            replace: (bool) $input->getOption('replace'),
            keepDownload: (bool) $input->getOption('keep-download'),
            strict: (bool) $input->getOption('strict'),
            label: is_string($input->getOption('label')) ? $input->getOption('label') : null,
            bindEnv: !(bool) $input->getOption('no-env'),
            preferPrebuilt: $preferPrebuilt,
        );

        foreach ($result['warnings'] as $warning) {
            $formatter->warning($warning);
        }

        $lock = $result['lock'];
        $formatter->success("Post Maclone ready (expires {$lock->expiresAt})");
        $formatter->info('  DATABASE_URL=' . $lock->databaseUrl);
        if ($lock->envBackupPath) {
            $formatter->info('  .env DB_* updated (backup: ' . $lock->envBackupPath . ')');
        }
        $formatter->info('  Tear down when finished: ngramx postmaclone down');

        return Command::SUCCESS;
    }

    private function runProduce(OutputFormatter $formatter, InputInterface $input): int
    {
        $formatter->welcome('Post Maclone produce (factory)');
        $all = (bool) $input->getOption('all');
        $datasetOpt = $input->getOption('dataset');
        $datasetName = is_string($datasetOpt) && $datasetOpt !== '' ? $datasetOpt : null;
        if (!$all && $datasetName === null) {
            $formatter->error('produce requires --all or --dataset <name>');

            return Command::FAILURE;
        }

        try {
            $configOpt = $input->getOption('config');
            $factoryPath = $this->configLoader->findFactoryConfigFile(
                is_string($configOpt) && $configOpt !== '' ? $configOpt : null
            );
            $factory = $this->configLoader->loadFactory($factoryPath);
        } catch (\Exception $e) {
            $formatter->error($e->getMessage());

            return Command::FAILURE;
        }

        $workRoot = dirname($factoryPath);
        $formatter->info('Factory config: ' . $factoryPath);

        $names = $all ? array_keys($factory->datasets) : [$datasetName];
        $failed = false;
        foreach ($names as $name) {
            if (!isset($factory->datasets[$name])) {
                $formatter->error("Unknown dataset '{$name}'");
                $failed = true;
                continue;
            }
            $formatter->info("Producing dataset: {$name}");
            try {
                $result = $this->producer->produceDataset(
                    $factory->datasets[$name],
                    $workRoot,
                    (bool) $input->getOption('strict'),
                );
                foreach ($result['warnings'] as $warning) {
                    $formatter->warning($warning);
                }
                $formatter->success(
                    "Published {$result['dataset']} → {$result['artifact_key']} "
                    . "({$result['size']} bytes, sha256 {$result['sha256']})"
                );
            } catch (PostmacloneException $e) {
                $formatter->error("Dataset {$name}: {$e->getMessage()}");
                $failed = true;
            }
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
