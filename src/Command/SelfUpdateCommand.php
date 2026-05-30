<?php

declare(strict_types=1);

namespace Ngramx\Command;

use Ngramx\Output\OutputFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SelfUpdateCommand extends Command
{
    private const GITHUB_REPO = 'ngramx/ngramx';
    private const GITHUB_API_URL = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';

    protected function configure(): void
    {
        $this
            ->setName('update')
            ->setDescription('Update Ngramx CLI to the latest version')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Check for updates without installing')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force update even if already on latest version');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);

        // Check if running as PHAR
        $pharPath = \Phar::running(false);
        if (empty($pharPath)) {
            $formatter->error('Update is only available when running as PHAR.');
            $formatter->info('You are running from source. Use git to update:');
            $formatter->info('  git pull origin main');
            $formatter->info('  composer install');
            return Command::FAILURE;
        }

        // Check if we have write permissions
        if (!is_writable($pharPath)) {
            $formatter->error("Cannot update: No write permission to $pharPath");
            $formatter->info('Try running with sudo:');
            $formatter->info('  sudo ngramx update');
            return Command::FAILURE;
        }

        $checkOnly = $input->getOption('check');
        $force = $input->getOption('force');

        try {
            $formatter->section('Checking for updates');

            // Get current version
            $application = $this->getApplication();
            if ($application === null) {
                throw new \RuntimeException('Application not set');
            }
            $currentVersion = $application->getVersion();
            $formatter->info("Current version: $currentVersion");

            // Fetch latest release info from GitHub
            $releaseInfo = $this->getLatestReleaseInfo();
            $latestVersion = $releaseInfo['version'];
            $formatter->info("Latest version: $latestVersion");

            // Compare versions
            if (!$force && version_compare($currentVersion, $latestVersion, '>=')) {
                $formatter->success('✓ Already running the latest version');
                return Command::SUCCESS;
            }

            if ($checkOnly) {
                if (version_compare($currentVersion, $latestVersion, '<')) {
                    $formatter->warning("Update available: $currentVersion → $latestVersion");
                    $formatter->info('Run without --check to install the update');
                }
                return Command::SUCCESS;
            }

            // Download and install update
            $formatter->section('Downloading update');
            $tempFile = $this->downloadLatestVersion($releaseInfo['download_url']);

            $formatter->section('Installing update');
            $this->installUpdate($tempFile, $pharPath);

            // Clean up
            @unlink($tempFile);

            $formatter->success("✓ Successfully updated to version $latestVersion");
            $formatter->info('');
            $formatter->info('Run "ngramx --version" to verify');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $formatter->error("Update failed: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    /**
     * @return array{version: string, download_url: string}
     */
    private function getLatestReleaseInfo(): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: Ngramx-CLI',
                    'Accept: application/json',
                ],
                'timeout' => 10,
            ],
        ]);

        $response = @file_get_contents(self::GITHUB_API_URL, false, $context);

        if ($response === false) {
            throw new \RuntimeException('Failed to fetch release information from GitHub');
        }

        $data = json_decode($response, true);

        if (!isset($data['tag_name']) || !isset($data['assets'])) {
            throw new \RuntimeException('Invalid response from GitHub API');
        }

        // Find the ngramx.phar asset
        $downloadUrl = null;
        foreach ($data['assets'] as $asset) {
            if (isset($asset['name']) && $asset['name'] === 'ngramx.phar') {
                $downloadUrl = $asset['browser_download_url'];
                break;
            }
        }

        if ($downloadUrl === null) {
            throw new \RuntimeException('ngramx.phar not found in latest release');
        }

        // Remove 'v' prefix if present
        $version = ltrim($data['tag_name'], 'v');

        return [
            'version' => $version,
            'download_url' => $downloadUrl,
        ];
    }

    private function downloadLatestVersion(string $downloadUrl): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'ngramx_update_');

        if ($tempFile === false) {
            throw new \RuntimeException('Failed to create temporary file');
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'User-Agent: Ngramx-CLI',
                'timeout' => 60,
                'follow_location' => 1,
            ],
        ]);

        $content = @file_get_contents($downloadUrl, false, $context);

        if ($content === false) {
            @unlink($tempFile);
            throw new \RuntimeException('Failed to download latest version from GitHub');
        }

        if (file_put_contents($tempFile, $content) === false) {
            @unlink($tempFile);
            throw new \RuntimeException('Failed to save downloaded file');
        }

        // Verify it's a valid PHAR by checking the file signature
        $fp = fopen($tempFile, 'rb');
        if ($fp === false) {
            @unlink($tempFile);
            throw new \RuntimeException('Failed to open downloaded file for verification');
        }

        $header = fread($fp, 4);
        fclose($fp);

        // Check for PHP PHAR signature (should start with #!/usr/bin/env php or <?php)
        if ($header === false || (!str_starts_with($content, '#!/') && !str_starts_with($content, '<?php'))) {
            @unlink($tempFile);
            throw new \RuntimeException('Downloaded file is not a valid PHAR');
        }

        // Additional check: verify it's executable PHP
        try {
            // Try to load it as a Phar (this will fail if it's not valid)
            new \Phar($tempFile);
        } catch (\Exception $e) {
            // If Phar loading fails but file looks like PHP, it might be a stub-only PHAR
            // Check file size - should be at least 10KB for a valid Ngramx PHAR
            $fileSize = filesize($tempFile);
            if ($fileSize === false || $fileSize < 10240) {
                @unlink($tempFile);
                throw new \RuntimeException('Downloaded file is not a valid PHAR: ' . $e->getMessage());
            }
            // If file is large enough and looks like PHP, proceed
        }

        return $tempFile;
    }

    private function installUpdate(string $tempFile, string $pharPath): void
    {
        // Create backup
        $backupPath = $pharPath . '.backup';
        if (!@copy($pharPath, $backupPath)) {
            throw new \RuntimeException('Failed to create backup');
        }

        // Replace with new version
        if (!@rename($tempFile, $pharPath)) {
            // Restore backup on failure
            @rename($backupPath, $pharPath);
            throw new \RuntimeException('Failed to install update');
        }

        // Make executable
        @chmod($pharPath, 0755);

        // Remove backup on success
        @unlink($backupPath);
    }
}
