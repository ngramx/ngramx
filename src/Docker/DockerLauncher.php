<?php

declare(strict_types=1);

namespace Ngramx\Docker;

use Ngramx\Output\OutputFormatter;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException;
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

    /**
     * Where Windows' System32 lives under the default WSL drive mount.
     */
    private const WSL_DEFAULT_SYSTEM32 = '/mnt/c/Windows/System32';

    /**
     * PowerShell's location relative to System32.
     */
    private const WINDOWS_POWERSHELL_RELATIVE_PATH = 'WindowsPowerShell/v1.0/powershell.exe';

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
        return $this->runLaunchCommand(['open', '-a', 'Docker'], 15);
    }

    protected function launchWindows(): bool
    {
        $exe = $this->findWindowsDockerExe();
        if ($exe === null) {
            return false;
        }

        // `start` returns immediately; the GUI app boots in the background.
        return $this->runLaunchCommand(['cmd.exe', '/c', 'start', '""', $exe], 15);
    }

    protected function launchWsl(): bool
    {
        // Docker Desktop runs on Windows; start it from the Windows side.
        //
        // We must address the interop binaries by their absolute /mnt path
        // rather than by name. WSL only appends the Windows PATH when
        // `interop.appendWindowsPath` is left on, and plenty of setups turn it
        // off (or run ngramx from a context with a trimmed PATH). In that case
        // a bare `cmd.exe` is unresolvable, Process fails before it ever starts
        // Docker, and the user just sees "Could not start Docker automatically"
        // even though Docker Desktop is installed and perfectly launchable.
        $exes = $this->wslVisibleDockerExes();

        foreach ($this->interopBinaries('cmd.exe') as $cmd) {
            foreach ($exes as $exe) {
                // `start` returns immediately; the GUI app boots in the background.
                if ($this->runLaunchCommand([$cmd, '/c', 'start', '""', $exe], 15)) {
                    return true;
                }
            }
        }

        // PowerShell fallback: Start-Process resolves the app through the
        // Windows side, which covers installs outside our known paths.
        $powershell = self::WINDOWS_POWERSHELL_RELATIVE_PATH;
        foreach ($this->interopBinaries($powershell) as $ps) {
            $launched = $this->runLaunchCommand([
                $ps,
                '-NoProfile',
                '-Command',
                "Start-Process -FilePath 'C:\\Program Files\\Docker\\Docker\\Docker Desktop.exe'",
            ], 20);

            if ($launched) {
                return true;
            }
        }

        return false;
    }

    /**
     * Docker Desktop install paths that are actually visible from this WSL
     * distro, in the order we should try them.
     *
     * Overridable so tests need not depend on the host having Docker Desktop
     * installed on a mounted Windows drive.
     *
     * @return list<string> Windows-style paths
     */
    protected function wslVisibleDockerExes(): array
    {
        $exes = [];
        foreach (self::WINDOWS_DOCKER_PATHS as $exe) {
            if ($this->wslWindowsPathExists($exe)) {
                $exes[] = $exe;
            }
        }

        return $exes;
    }

    /**
     * Candidate ways to invoke a Windows interop binary from WSL: every
     * mounted drive's System32 copy first (absolute, always resolvable), then
     * the bare name as a last resort for setups where the Windows PATH *is*
     * inherited but the drive is mounted somewhere we do not scan.
     *
     * @param string $relativePath Path under System32, e.g. `cmd.exe`
     * @return list<string>
     */
    protected function interopBinaries(string $relativePath): array
    {
        $candidates = [];

        foreach ($this->system32Directories() as $dir) {
            $path = $dir . '/' . $relativePath;
            if (is_file($path)) {
                $candidates[] = $path;
            }
        }

        $candidates[] = basename($relativePath);

        return $candidates;
    }

    /**
     * Mounted Windows System32 directories, C: first.
     *
     * @return list<string>
     */
    private function system32Directories(): array
    {
        $dirs = [];

        if (is_dir(self::WSL_DEFAULT_SYSTEM32)) {
            $dirs[] = self::WSL_DEFAULT_SYSTEM32;
        }

        foreach (glob('/mnt/*/Windows/System32') ?: [] as $dir) {
            if ($dir !== self::WSL_DEFAULT_SYSTEM32 && is_dir($dir)) {
                $dirs[] = $dir;
            }
        }

        return $dirs;
    }

    /**
     * Run one launch attempt. Overridable so tests can assert on the command
     * we build without spawning Windows processes.
     *
     * Launching a desktop app is fire-and-forget, and some launchers never
     * hand the terminal back: under WSL, `cmd.exe /c start` keeps running for
     * as long as it feels like it. Symfony turns that into a
     * ProcessTimedOutException, which used to escape and abort the whole
     * command — with Docker Desktop already booting happily in the background.
     * A timeout therefore counts as "we asked"; whether it worked is settled
     * by polling the daemon, not by an exit code.
     *
     * @param list<string> $command
     */
    protected function runLaunchCommand(array $command, int $timeout): bool
    {
        $process = new Process($command);
        $process->setTimeout($timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return true;
        } catch (RuntimeException) {
            // The binary could not be spawned at all — try the next candidate.
            return false;
        }

        return $process->isSuccessful();
    }

    protected function launchLinux(): bool
    {
        // Docker Desktop for Linux ships a user-level systemd unit.
        if ($this->runLaunchCommand(['systemctl', '--user', 'start', 'docker-desktop'], 15)) {
            return true;
        }

        // Native docker daemon (requires root / passwordless sudo). Best effort.
        return $this->runLaunchCommand(['systemctl', 'start', 'docker'], 15);
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

        try {
            $process->run();
        } catch (ProcessTimedOutException | RuntimeException) {
            // A daemon that is still booting can hang the probe, and on WSL the
            // `docker` shim itself disappears until Docker Desktop remounts it.
            // Either way this attempt simply failed; keep polling.
            return false;
        }

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
