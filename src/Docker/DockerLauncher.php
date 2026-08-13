<?php

declare(strict_types=1);

namespace Ngramx\Docker;

use Ngramx\Output\OutputFormatter;
use Symfony\Component\Process\Process;

/**
 * Starts the Docker daemon (or, on desktop platforms, the Docker Desktop
 * app that hosts it) when {@see DockerCompose::isDockerRunning()} reports
 * it as down.
 *
 * The right way to bring Docker up depends entirely on the host:
 *
 *   - macOS:          `open -a Docker` launches Docker Desktop.
 *   - Windows (native): start the Docker Desktop .exe directly.
 *   - WSL:            Docker Desktop runs on Windows, so we shell out to
 *                     `cmd.exe`/`powershell.exe` to launch it from there.
 *   - Linux:          try the Docker Desktop user unit, then the system
 *                     docker service (the latter needs passwordless sudo).
 *
 * After asking the platform to start, we poll `docker info` until the
 * daemon answers or the wait budget runs out, so the calling command can
 * proceed without the user re-running it.
 */
class DockerLauncher
{
    /**
     * Maximum number of seconds to wait for the daemon to answer after
     * launching Docker Desktop. Docker Desktop's cold start can take a
     * while, especially under WSL2, so this is deliberately generous.
     */
    private const WAIT_TIMEOUT = 120;

    /**
     * Seconds between daemon probes while waiting.
     */
    private const POLL_INTERVAL = 2;

    /**
     * Per-probe timeout for `docker info` while polling. Short on purpose:
     * a daemon that is genuinely down fails fast (connection refused), and
     * a daemon that is mid-startup will keep the connection open — we'd
     * rather re-probe than block for the full {@see waitTimeout()} on one
     * call.
     */
    private const PROBE_TIMEOUT = 5;

    /**
     * Common Docker Desktop install locations on Windows, checked in order.
     */
    private const WINDOWS_DOCKER_PATHS = [
        'C:\Program Files\Docker\Docker\Docker Desktop.exe',
        'C:\Program Files (x86)\Docker\Docker\Docker Desktop.exe',
    ];

    public function __construct(
        private readonly DockerCompose $dockerCompose,
        private readonly PlatformDetector $platformDetector = new PlatformDetector(),
    ) {
    }

    /**
     * Make sure the Docker daemon is reachable. If it isn't, attempt to
     * start it for the current platform and poll until it answers (or the
     * wait budget expires). Returns true once the daemon is up, false if it
     * could not be started — in which case an explanatory message has
     * already been printed.
     */
    public function ensureRunning(OutputFormatter $formatter): bool
    {
        if ($this->dockerCompose->isDockerRunning()) {
            return true;
        }

        $platform = $this->platformDetector->detect();

        if (!$platform->canAutoStartDocker()) {
            return false;
        }

        $formatter->info('Docker is not running — starting it for you...');

        if (!$this->launch($platform)) {
            $formatter->warning('Could not start Docker automatically.');
            return false;
        }

        if ($this->waitForDaemon($formatter)) {
            return true;
        }

        $formatter->warning(sprintf(
            'Docker did not become ready within %d seconds. Please start it manually and re-run.',
            self::WAIT_TIMEOUT,
        ));

        return false;
    }

    /**
     * Launch Docker for the given platform. Overridable by tests so the
     * real process execution can be swapped out.
     */
    protected function launch(Platform $platform): bool
    {
        return match ($platform) {
            Platform::Macos => $this->launchMacos(),
            Platform::Windows => $this->launchWindows(),
            Platform::Wsl => $this->launchWsl(),
            Platform::Linux => $this->launchLinux(),
            Platform::Unknown => false,
        };
    }

    protected function launchMacos(): bool
    {
        $process = new Process(['open', '-a', 'Docker']);
        $process->setTimeout(15);
        $process->run();

        return $process->isSuccessful();
    }

    protected function launchWindows(): bool
    {
        $exe = $this->findWindowsDockerExe();
        if ($exe === null) {
            return false;
        }

        // `start` returns immediately; the GUI app boots in the background.
        $process = new Process(['cmd.exe', '/c', 'start', '""', $exe]);
        $process->setTimeout(15);
        $process->run();

        return $process->isSuccessful();
    }

    protected function launchWsl(): bool
    {
        // Docker Desktop runs on Windows; start it from the Windows side.
        // Prefer cmd.exe interop against a known install path (fast, no
        // PowerShell startup cost) and fall back to PowerShell's
        // Start-Process, which can resolve the binary via the Windows PATH.
        foreach (self::WINDOWS_DOCKER_PATHS as $exe) {
            if ($this->wslWindowsPathExists($exe)) {
                $process = new Process(['cmd.exe', '/c', 'start', '""', $exe]);
                $process->setTimeout(15);
                $process->run();

                if ($process->isSuccessful()) {
                    return true;
                }
            }
        }

        $ps = new Process([
            'powershell.exe',
            '-NoProfile',
            '-Command',
            "Start-Process -FilePath 'C:\\Program Files\\Docker\\Docker\\Docker Desktop.exe'",
        ]);
        $ps->setTimeout(20);
        $ps->run();

        return $ps->isSuccessful();
    }

    protected function launchLinux(): bool
    {
        // Docker Desktop for Linux ships a user-level systemd unit.
        $process = new Process(['systemctl', '--user', 'start', 'docker-desktop']);
        $process->setTimeout(15);
        $process->run();

        if ($process->isSuccessful()) {
            return true;
        }

        // Native docker daemon (requires root / passwordless sudo). Best effort.
        $process = new Process(['systemctl', 'start', 'docker']);
        $process->setTimeout(15);
        $process->run();

        return $process->isSuccessful();
    }

    private function findWindowsDockerExe(): ?string
    {
        foreach (self::WINDOWS_DOCKER_PATHS as $exe) {
            if (is_file($exe)) {
                return $exe;
            }
        }

        return null;
    }

    /**
     * Check whether a Windows path is visible from WSL by translating it
     * to its /mnt/<drive>/... mount and stat-ing it.
     */
    private function wslWindowsPathExists(string $windowsPath): bool
    {
        $lower = strtolower($windowsPath);
        $wslPath = preg_replace('/^([a-z]):/i', '/mnt/$1', $lower);
        if (!is_string($wslPath)) {
            return false;
        }

        $wslPath = str_replace('\\', '/', $wslPath);

        return is_file($wslPath);
    }

    private function waitForDaemon(OutputFormatter $formatter): bool
    {
        $deadline = time() + $this->waitTimeout();

        while (time() < $deadline) {
            if ($this->probeDaemon()) {
                $formatter->info('Docker is ready.');
                return true;
            }

            $this->sleep($this->pollInterval());
        }

        return false;
    }

    /**
     * Overridable hook for tests so the real `docker info` call can be
     * swapped out.
     */
    protected function probeDaemon(): bool
    {
        $process = new Process(['docker', 'info']);
        $process->setTimeout(self::PROBE_TIMEOUT);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Overridable hook for tests to avoid real sleeps while polling.
     */
    protected function sleep(int $seconds): void
    {
        sleep($seconds);
    }

    /**
     * Overridable hook for tests. Seconds to wait for the daemon after launch.
     */
    protected function waitTimeout(): int
    {
        return self::WAIT_TIMEOUT;
    }

    /**
     * Overridable hook for tests. Seconds between daemon probes.
     */
    protected function pollInterval(): int
    {
        return self::POLL_INTERVAL;
    }
}
