<?php

declare(strict_types=1);

namespace Ngramx\Command;

use Ngramx\Config\ConfigLoader;
use Ngramx\Output\OutputFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class SecureCommand extends Command
{
    public function __construct(
        private readonly ConfigLoader $configLoader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('secure')
            ->setDescription('Generate browser-trusted SSL certificates using mkcert');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);

        try {
            $configPath = $this->configLoader->findConfigFile();
            $config = $this->configLoader->load($configPath);
        } catch (\Exception $e) {
            $formatter->error("Failed to load configuration: {$e->getMessage()}");
            return Command::FAILURE;
        }

        $configDir = dirname($configPath);

        $formatter->welcome('Setting up local SSL');

        if (!$this->isMkcertInstalled()) {
            $this->printInstallInstructions($formatter);
            return Command::FAILURE;
        }

        $formatter->info('✓ mkcert is installed');

        $hostname = $this->extractHostname($config->docker->appUrl);
        if ($hostname === null) {
            $formatter->error('Could not extract hostname from docker.app_url: ' . $config->docker->appUrl);
            return Command::FAILURE;
        }

        $formatter->section('Installing local CA');
        if (!$this->runMkcertInstall($formatter)) {
            return Command::FAILURE;
        }

        $sslDir = $configDir . '/' . $config->docker->sslPath;
        if (!is_dir($sslDir)) {
            if (!mkdir($sslDir, 0755, true)) {
                $formatter->error("Failed to create SSL directory: $sslDir");
                return Command::FAILURE;
            }
            $formatter->info("✓ Created $sslDir");
        }

        $certFile = $sslDir . '/' . $hostname . '.crt';
        $keyFile = $sslDir . '/' . $hostname . '.key';

        $formatter->section('Generating certificate');
        $formatter->info("  Hostname: $hostname");
        $formatter->info("  Cert:     $certFile");
        $formatter->info("  Key:      $keyFile");

        if (!$this->runMkcertGenerate($hostname, $certFile, $keyFile, $formatter)) {
            return Command::FAILURE;
        }

        $formatter->section('Done');
        $formatter->success('✓ Browser-trusted SSL certificate generated!');
        $formatter->info('');
        $formatter->info('Your browser will now trust https://' . $hostname);
        $formatter->info('Run `ngramx up` to start your environment with SSL.');

        return Command::SUCCESS;
    }

    private function isMkcertInstalled(): bool
    {
        $process = new Process(['which', 'mkcert']);
        $process->run();

        return $process->isSuccessful();
    }

    private function printInstallInstructions(OutputFormatter $formatter): void
    {
        $formatter->error('mkcert is not installed.');
        $formatter->info('');
        $formatter->info('Install it for your platform:');
        $formatter->info('');

        $os = PHP_OS_FAMILY;

        if ($os === 'Darwin') {
            $formatter->success('  macOS:');
            $formatter->info('    brew install mkcert');
            $formatter->info('    mkcert -install');
        } elseif ($os === 'Windows') {
            $formatter->success('  Windows (Chocolatey):');
            $formatter->info('    choco install mkcert');
            $formatter->info('    mkcert -install');
            $formatter->info('');
            $formatter->success('  Windows (Scoop):');
            $formatter->info('    scoop install mkcert');
            $formatter->info('    mkcert -install');
        } elseif ($os === 'Linux') {
            $formatter->success('  Linux (apt):');
            $formatter->info('    sudo apt install libnss3-tools');
            $formatter->info('    brew install mkcert');
            $formatter->info('    mkcert -install');
        } else {
            $formatter->success('  macOS:');
            $formatter->info('    brew install mkcert && mkcert -install');
            $formatter->info('');
            $formatter->success('  Windows:');
            $formatter->info('    choco install mkcert && mkcert -install');
            $formatter->info('');
            $formatter->success('  Linux:');
            $formatter->info('    sudo apt install libnss3-tools && brew install mkcert && mkcert -install');
        }

        $formatter->info('');
        $formatter->info('Then run `ngramx secure` again.');
    }

    private function extractHostname(string $appUrl): ?string
    {
        $parsed = parse_url($appUrl);
        if ($parsed === false || !isset($parsed['host'])) {
            return null;
        }

        return $parsed['host'];
    }

    private function runMkcertInstall(OutputFormatter $formatter): bool
    {
        $process = new Process(['mkcert', '-install']);
        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful()) {
            $errorOutput = $process->getErrorOutput();
            // mkcert -install may write to stderr even on success (e.g. "The local CA is already installed")
            if ($process->getExitCode() !== 0) {
                $formatter->error('Failed to install local CA: ' . $errorOutput);
                $formatter->info('You may need to run this with admin privileges.');
                return false;
            }
        }

        $formatter->info('✓ Local CA is installed and trusted');
        return true;
    }

    private function runMkcertGenerate(string $hostname, string $certFile, string $keyFile, OutputFormatter $formatter): bool
    {
        $process = new Process([
            'mkcert',
            '-cert-file', $certFile,
            '-key-file', $keyFile,
            $hostname,
        ]);
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful()) {
            $formatter->error('Failed to generate certificate: ' . $process->getErrorOutput());
            return false;
        }

        $formatter->info('✓ Certificate generated successfully');
        return true;
    }
}
