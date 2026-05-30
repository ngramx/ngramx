<?php

declare(strict_types=1);

namespace Cortex\Command;

use Cortex\Agents\AgentsSyncOrchestrator;
use Cortex\Config\ConfigLoader;
use Cortex\Config\Exception\ConfigException;
use Cortex\Config\Schema\AgentsConfig;
use Cortex\Config\Validator\ConfigValidator;
use Cortex\Output\OutputFormatter;
use Cortex\Templates\TemplateDirectory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class InitCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('init')
            ->setDescription('Initialize Cortex configuration and directory structure')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing files')
            ->addOption('skip-yaml', null, InputOption::VALUE_NONE, 'Skip creating cortex.yml')
            ->addOption('skip-claude', null, InputOption::VALUE_NONE, 'Skip creating/updating ~/.claude files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);

        try {
            $cwd = getcwd();
            if ($cwd === false) {
                throw new \RuntimeException('Failed to get current working directory');
            }

            $homeDir = $this->getHomeDirectory();
            $force = $input->getOption('force');
            $skipYaml = $input->getOption('skip-yaml');
            $skipClaude = $input->getOption('skip-claude');

            $formatter->welcome('Initializing Cortex');
            $formatter->section('Setting up directory structure');

            $this->createCortexDirectory($cwd, $formatter);

            if (!$skipYaml) {
                $this->createCortexYml($cwd, $formatter, $force);
            }

            if (!$skipClaude) {
                $this->createClaudeUserFiles($homeDir, $formatter);
            } else {
                $formatter->info('⊘ Skipped ~/.claude files (--skip-claude)');
            }

            // Sync agent instructions to all configured targets
            $this->syncAgentTargets($cwd, $formatter);

            $this->showSuccessMessage($formatter, $skipYaml, $skipClaude);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $formatter->error("Initialization failed: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    private function syncAgentTargets(string $projectRoot, OutputFormatter $formatter): void
    {
        try {
            $configLoader = new ConfigLoader(new ConfigValidator());
            $config = $configLoader->load($projectRoot . '/cortex.yml');
            $agentsConfig = $config->agents;
        } catch (ConfigException) {
            $agentsConfig = new AgentsConfig();
        }

        $orchestrator = new AgentsSyncOrchestrator();
        $result = $orchestrator->sync($projectRoot, $agentsConfig);

        $targetsChanged = $result['targets_changed'];
        $skillsChanged = $result['skills_changed'];

        if ($targetsChanged !== []) {
            foreach ($targetsChanged as $target) {
                $formatter->info("✓ Synced agent target: $target");
            }
        } else {
            $formatter->info('✓ Agent targets already up to date');
        }

        if ($skillsChanged) {
            $formatter->info('✓ Skills synchronized');
        }
    }

    private function getHomeDirectory(): string
    {
        $home = getenv('HOME');
        if ($home === false || $home === '') {
            $home = getenv('USERPROFILE');
        }
        if ($home === false || $home === '') {
            throw new \RuntimeException('Unable to determine home directory');
        }
        return $home;
    }

    private function createCortexDirectory(string $cwd, OutputFormatter $formatter): void
    {
        $cortexDir = $cwd . '/.cortex';

        if (!is_dir($cortexDir)) {
            if (!mkdir($cortexDir, 0755, true)) {
                throw new \RuntimeException("Failed to create directory: $cortexDir");
            }
            $formatter->info('✓ Created .cortex/ directory');
        } else {
            $formatter->info('✓ .cortex/ directory already exists');
        }

        $subdirectories = [
            'tickets',
            'specs',
            'meetings',
        ];

        foreach ($subdirectories as $subdir) {
            $path = $cortexDir . '/' . $subdir;
            if (!is_dir($path)) {
                if (!mkdir($path, 0755, true)) {
                    throw new \RuntimeException("Failed to create directory: $path");
                }
                $formatter->info("✓ Created .cortex/$subdir/ directory");
            }
        }

        foreach ($subdirectories as $subdir) {
            $this->createGitkeep($cortexDir . '/' . $subdir, $formatter, $subdir);
        }

        $this->createCortexReadme($cortexDir, $formatter);
    }

    private function createGitkeep(string $directory, OutputFormatter $formatter, string $subdirName): void
    {
        $gitkeepPath = $directory . '/.gitkeep';

        if (!file_exists($gitkeepPath)) {
            if (file_put_contents($gitkeepPath, '') === false) {
                throw new \RuntimeException("Failed to create .gitkeep in $directory");
            }
            $formatter->info("✓ Created .cortex/$subdirName/.gitkeep");
        }
    }

    private function createCortexReadme(string $cortexDir, OutputFormatter $formatter): void
    {
        $readmePath = $cortexDir . '/README.md';
        $templatePath = $this->getTemplatePath('cortex-readme.md.template');

        if (!file_exists($templatePath)) {
            throw new \RuntimeException("Template file not found: $templatePath");
        }

        $content = file_get_contents($templatePath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read template: $templatePath");
        }

        if (file_put_contents($readmePath, $content) === false) {
            throw new \RuntimeException('Failed to create README.md');
        }

        $formatter->info('✓ Created .cortex/README.md');
    }

    private function createCortexYml(string $cwd, OutputFormatter $formatter, bool $force): void
    {
        $cortexYmlPath = $cwd . '/cortex.yml';

        if (file_exists($cortexYmlPath) && !$force) {
            $formatter->warning('⚠ cortex.yml already exists (use --force to overwrite)');
            return;
        }

        $templatePath = $this->getTemplatePath('cortex.yml.template');

        if (!file_exists($templatePath)) {
            throw new \RuntimeException("Template file not found: $templatePath");
        }

        $content = file_get_contents($templatePath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read template: $templatePath");
        }

        if (file_put_contents($cortexYmlPath, $content) === false) {
            throw new \RuntimeException('Failed to create cortex.yml');
        }

        $formatter->info('✓ Created cortex.yml');
    }

    /**
     * Creates user-wide ~/.claude/ files (rules and CLAUDE.md).
     * These are personal/global — distinct from the project-level CLAUDE.md target.
     */
    private function createClaudeUserFiles(string $homeDir, OutputFormatter $formatter): void
    {
        $claudeDir = $homeDir . '/.claude';
        $rulesDir = $claudeDir . '/rules';

        if (!is_dir($claudeDir) && !mkdir($claudeDir, 0755, true)) {
            throw new \RuntimeException("Failed to create directory: $claudeDir");
        }

        if (!is_dir($rulesDir) && !mkdir($rulesDir, 0755, true)) {
            throw new \RuntimeException("Failed to create directory: $rulesDir");
        }

        // Write ~/.claude/CLAUDE.md
        $claudeMdPath = $claudeDir . '/CLAUDE.md';
        $templatePath = $this->getTemplatePath('CLAUDE.md.template');

        if (file_exists($templatePath)) {
            $templateContent = file_get_contents($templatePath);
            if ($templateContent !== false) {
                $this->writeClaudeMdWithMarkers($claudeMdPath, $templateContent, $formatter);
            }
        }

        $formatter->info('✓ ~/.claude/ files updated');
    }

    private const CORTEX_MARKER_START = '<!-- CORTEX START -->';
    private const CORTEX_MARKER_END = '<!-- CORTEX END -->';

    private function writeClaudeMdWithMarkers(string $path, string $templateContent, OutputFormatter $formatter): void
    {
        $cortexSection = self::CORTEX_MARKER_START . "\n" . $templateContent . "\n" . self::CORTEX_MARKER_END;

        if (file_exists($path)) {
            $existing = file_get_contents($path);
            if ($existing === false) {
                return;
            }

            if (str_contains($existing, self::CORTEX_MARKER_START)) {
                $pattern = '/' . preg_quote(self::CORTEX_MARKER_START, '/') . '.*?' . preg_quote(self::CORTEX_MARKER_END, '/') . '/s';
                $newContent = preg_replace($pattern, $cortexSection, $existing) ?? $existing;
                file_put_contents($path, $newContent);
            } else {
                file_put_contents($path, $existing . "\n\n" . $cortexSection);
            }
        } else {
            file_put_contents($path, $cortexSection);
        }
    }

    private function getTemplatePath(string $templateName): string
    {
        return TemplateDirectory::path($templateName);
    }

    private function showSuccessMessage(OutputFormatter $formatter, bool $skipYaml, bool $skipClaude = false): void
    {
        $formatter->section('Initialization Complete');
        $formatter->info('');
        $formatter->success('✓ Cortex initialized successfully!');

        $formatter->info('');
        $formatter->info('Created (project):');
        $formatter->info('  ✓ .cortex/ directory structure');

        if (!$skipYaml) {
            $formatter->info('  ✓ cortex.yml');
        }

        $formatter->info('  ✓ Agent instructions synced to configured targets');

        if (!$skipClaude) {
            $formatter->info('');
            $formatter->info('Created (user-wide):');
            $formatter->info('  ✓ ~/.claude/CLAUDE.md');
        }

        $formatter->info('');
        $formatter->info('Next steps:');

        if (!$skipYaml) {
            $formatter->info('  1. Review and customize cortex.yml for your project');
            $formatter->info('  2. Add `agents:` section to configure targets and skills');
            $formatter->info('  3. Run: cortex up');
        } else {
            $formatter->info('  1. Create a cortex.yml file (see cortex.example.yml)');
            $formatter->info('  2. Run: cortex up');
        }

        $formatter->info('');
        $formatter->info('Agent sync: the Cortex-managed sections are refreshed on every cortex command.');
        $formatter->info('Configure targets in cortex.yml under `agents.targets` and `agents.skills`.');
        $formatter->info('');
        $formatter->info('For help: cortex --help');
    }
}
