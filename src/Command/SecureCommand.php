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
        $this->ensureCertutil($formatter);
        // mkcert only writes the CA into the browser (NSS) store if that store
        // already exists, so create it first — otherwise Chrome/Chromium never
        // trust the cert even though the system store has the CA.
        $this->ensureNssStore($formatter);
        // Non-fatal: even if the CA can't be trusted (e.g. no TTY for sudo), we
        // still generate the cert so nginx can serve HTTPS. The user is told how
        // to finish trusting it.
        $caInstalled = $this->runMkcertInstall($formatter);

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

        // On WSL the browser usually runs on Windows, which has its own trust
        // store. Bridge the CA across so the Windows browser trusts it too.
        $this->trustCaOnWindowsIfWsl($formatter);

        $formatter->section('Done');
        $formatter->success('✓ SSL certificate generated!');
        $formatter->info('');
        if ($caInstalled) {
            $formatter->info('Your browser will now trust https://' . $hostname);
        } else {
            $formatter->info('The certificate is in place, but the local CA is not trusted yet.');
            $formatter->info('Re-run `ngramx secure` from an interactive terminal to finish trusting it.');
        }
        $formatter->info('Run `ngramx up` to start your environment with SSL.');

        return Command::SUCCESS;
    }

    private function isMkcertInstalled(): bool
    {
        return $this->isInstalled('mkcert');
    }

    private function isInstalled(string $binary): bool
    {
        $process = new Process(['which', $binary]);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * On Linux, mkcert needs `certutil` (from libnss3-tools / nss-tools) to add
     * the CA to the NSS store that Chrome, Chromium and Playwright read. Without
     * it `mkcert -install` silently skips browser trust, so certs never turn
     * green in the browser. Install it automatically when it's missing.
     *
     * No-op on macOS/Windows, where mkcert trusts the CA via the native store.
     */
    private function ensureCertutil(OutputFormatter $formatter): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || $this->isInstalled('certutil')) {
            return;
        }

        $installCommand = $this->certutilInstallCommand();
        if ($installCommand === null) {
            $formatter->warning('certutil is missing and no known package manager was detected.');
            $formatter->info('Install libnss3-tools (Debian/Ubuntu), nss-tools (Fedora) or nss (Arch), then re-run.');
            return;
        }

        $formatter->info('Installing certutil so browsers can trust the CA (may prompt for your password)...');

        $process = Process::fromShellCommandline($installCommand);
        $process->setTimeout(300);
        $this->enableTtyIfAvailable($process);
        $process->run();

        if (!$process->isSuccessful()) {
            $formatter->warning('Could not install certutil automatically.');
            $formatter->info('Install it manually and re-run: ' . $installCommand);
            return;
        }

        $formatter->info('✓ certutil installed');
    }

    private function certutilInstallCommand(): ?string
    {
        return match (true) {
            $this->isInstalled('apt-get') => 'sudo apt-get update && sudo apt-get install -y libnss3-tools',
            $this->isInstalled('dnf') => 'sudo dnf install -y nss-tools',
            $this->isInstalled('pacman') => 'sudo pacman -Sy --noconfirm nss',
            $this->isInstalled('zypper') => 'sudo zypper install -y mozilla-nss-tools',
            default => null,
        };
    }

    /**
     * Chrome/Chromium (and Playwright) read CAs from the per-user NSS store at
     * ~/.pki/nssdb. mkcert only writes there if the store already exists — it
     * won't create it — so a fresh machine gets the CA in the system store but
     * not the browser store. Initialise an empty NSS store so mkcert can
     * populate it on the `-install` that follows.
     */
    private function ensureNssStore(OutputFormatter $formatter): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !$this->isInstalled('certutil')) {
            return;
        }

        $home = getenv('HOME');
        if ($home === false || $home === '') {
            return;
        }

        $nssDir = $home . '/.pki/nssdb';
        if (is_file($nssDir . '/cert9.db') || is_file($nssDir . '/cert8.db')) {
            return; // already initialised
        }

        if (!is_dir($nssDir) && !mkdir($nssDir, 0700, true) && !is_dir($nssDir)) {
            return;
        }

        $process = new Process(['certutil', '-N', '--empty-password', '-d', 'sql:' . $nssDir]);
        $process->run();

        if ($process->isSuccessful()) {
            $formatter->info('✓ Initialised the browser certificate store (~/.pki/nssdb)');
        }
    }

    /**
     * Under WSL the browser normally runs on Windows, whose trust store is
     * separate from the Linux one mkcert just updated. Copy the mkcert root CA
     * to a Windows-visible path and import it into the current user's Windows
     * trust store (Cert:\CurrentUser\Root — no admin needed) so Chrome/Edge on
     * Windows trust the cert too. Best-effort and non-fatal.
     */
    private function trustCaOnWindowsIfWsl(OutputFormatter $formatter): void
    {
        if (!$this->isWsl() || !$this->isInstalled('powershell.exe') || !$this->isInstalled('wslpath')) {
            return;
        }

        $caRoot = $this->mkcertCaRoot();
        if ($caRoot === null) {
            return;
        }

        $rootCa = $caRoot . '/rootCA.pem';
        if (!is_file($rootCa)) {
            return;
        }

        $formatter->section('Trusting the CA on Windows (WSL detected)');

        $winTemp = $this->capture(['powershell.exe', '-NoProfile', '-Command', 'Write-Output $env:TEMP']);
        if ($winTemp === null || $winTemp === '') {
            $formatter->warning('Could not resolve the Windows temp directory; skipping Windows trust.');
            return;
        }

        $wslTemp = $this->capture(['wslpath', '-u', $winTemp]);
        if ($wslTemp === null || $wslTemp === '') {
            $formatter->warning('Could not translate the Windows temp path; skipping Windows trust.');
            return;
        }

        $wslDest = rtrim($wslTemp, '/') . '/ngramx-rootCA.pem';
        if (!@copy($rootCa, $wslDest)) {
            $formatter->warning('Could not stage the CA for Windows; skipping Windows trust.');
            return;
        }

        $winPath = rtrim($winTemp, '\\') . '\\ngramx-rootCA.pem';
        $psCommand = "Import-Certificate -FilePath '" . $winPath . "' "
            . '-CertStoreLocation Cert:\CurrentUser\Root | Out-Null';

        $process = new Process(['powershell.exe', '-NoProfile', '-Command', $psCommand]);
        $process->setTimeout(60);
        $process->run();

        @unlink($wslDest);

        if (!$process->isSuccessful()) {
            $formatter->warning('Could not add the CA to the Windows trust store automatically.');
            $error = trim($process->getErrorOutput());
            if ($error !== '') {
                $formatter->info($error);
            }
            return;
        }

        $formatter->info('✓ CA added to the Windows user trust store (Cert:\CurrentUser\Root)');
        $formatter->info('Fully restart your Windows browser for it to take effect.');
    }

    private function isWsl(): bool
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return false;
        }

        if (getenv('WSL_DISTRO_NAME') !== false) {
            return true;
        }

        $version = @file_get_contents('/proc/version');

        return $version !== false && stripos($version, 'microsoft') !== false;
    }

    private function mkcertCaRoot(): ?string
    {
        $caRoot = $this->capture(['mkcert', '-CAROOT']);

        return ($caRoot === null || $caRoot === '') ? null : $caRoot;
    }

    /**
     * Run a command and return its trimmed stdout, or null if it failed.
     *
     * @param list<string> $command
     */
    private function capture(array $command): ?string
    {
        $process = new Process($command);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        return trim($process->getOutput());
    }

    /**
     * Attach the current TTY so interactive prompts (the sudo password mkcert
     * and the package manager ask for) actually reach the user. Silently skipped
     * when there is no TTY (CI, piped output); callers degrade gracefully.
     */
    private function enableTtyIfAvailable(Process $process): void
    {
        try {
            if (Process::isTtySupported() && \defined('STDIN') && @stream_isatty(\STDIN)) {
                $process->setTty(true);
            }
        } catch (\Throwable) {
            // Some environments report TTY support inaccurately; fall back to a
            // non-interactive run rather than crashing.
        }
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
        $process->setTimeout(120);
        // mkcert shells out to `sudo` to write the system trust store; give it a
        // TTY so it can prompt for the password instead of failing outright.
        $this->enableTtyIfAvailable($process);
        $process->run();

        if (!$process->isSuccessful()) {
            $formatter->warning('Could not install the local CA into the trust store.');
            $errorOutput = trim($process->getErrorOutput());
            if ($errorOutput !== '') {
                $formatter->info($errorOutput);
            }
            $formatter->info('The certificate will still be generated, but browsers may not trust it yet.');
            $formatter->info('Re-run `ngramx secure` from an interactive terminal so mkcert can prompt for sudo.');
            return false;
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
