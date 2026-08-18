<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Docker;

use Ngramx\Docker\DockerCompose;
use Ngramx\Docker\DockerLauncher;
use Ngramx\Docker\Platform;
use Ngramx\Docker\PlatformDetector;
use Ngramx\Output\OutputFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A {@see DockerLauncher} subclass whose side-effecting hooks (the actual
 * Docker launch, the `docker info` probe, and the sleep between probes) are
 * swapped out for configurable stubs so the orchestration logic can be
 * exercised without touching the host.
 */
class StubDockerLauncher extends DockerLauncher
{
    public bool $launchCalled = false;
    public bool $launchSucceeds = true;
    public bool $probeSucceeds = true;
    public int $waitTimeout = 120;
    public int $pollInterval = 2;
    public int $sleepCalls = 0;

    protected function launch(Platform $platform): bool
    {
        $this->launchCalled = true;

        return $this->launchSucceeds;
    }

    protected function probeDaemon(): bool
    {
        return $this->probeSucceeds;
    }

    protected function sleep(int $seconds): void
    {
        $this->sleepCalls++;
    }

    protected function waitTimeout(): int
    {
        return $this->waitTimeout;
    }

    protected function pollInterval(): int
    {
        return $this->pollInterval;
    }
}

/**
 * A launcher that runs the *real* WSL launch logic but records the commands
 * it would spawn instead of executing them, so we can assert that interop
 * binaries are addressed by absolute path.
 */
class RecordingWslLauncher extends DockerLauncher
{
    /** @var list<list<string>> */
    public array $commands = [];

    /** @var list<string> */
    public array $succeedingBinaries = [];

    /**
     * Stubbed so the test does not depend on Docker Desktop being installed
     * on a mounted Windows drive — CI runs on plain Linux, where no /mnt/c
     * exists and the real scan would legitimately come back empty.
     *
     * @var list<string>
     */
    public array $dockerExes = ['C:\\Program Files\\Docker\\Docker\\Docker Desktop.exe'];

    /** @param list<string> $interop */
    public function __construct(DockerCompose $dockerCompose, private readonly array $interop)
    {
        parent::__construct($dockerCompose);
    }

    public function launchWslForTest(): bool
    {
        return $this->launch(Platform::Wsl);
    }

    protected function interopBinaries(string $relativePath): array
    {
        $name = basename($relativePath);
        $found = array_values(array_filter(
            $this->interop,
            static fn (string $path): bool => str_ends_with($path, $name),
        ));
        $found[] = $name;

        return $found;
    }

    protected function wslVisibleDockerExes(): array
    {
        return $this->dockerExes;
    }

    protected function runLaunchCommand(array $command, int $timeout): bool
    {
        $this->commands[] = $command;

        return in_array($command[0], $this->succeedingBinaries, true);
    }
}

class DockerLauncherTest extends TestCase
{
    public function test_returns_true_immediately_when_daemon_already_running(): void
    {
        $compose = $this->createMock(DockerCompose::class);
        $compose->method('isDockerRunning')->willReturn(true);

        $launcher = new StubDockerLauncher(
            $compose,
            $this->detector(Platform::Macos),
        );

        $this->assertTrue($launcher->ensureRunning($this->formatter()));
        $this->assertFalse($launcher->launchCalled);
    }

    public function test_returns_false_when_platform_cannot_auto_start(): void
    {
        $compose = $this->createMock(DockerCompose::class);
        $compose->method('isDockerRunning')->willReturn(false);

        $launcher = new StubDockerLauncher(
            $compose,
            $this->detector(Platform::Unknown),
        );

        $this->assertFalse($launcher->ensureRunning($this->formatter()));
        $this->assertFalse($launcher->launchCalled);
    }

    public function test_launches_and_proceeds_when_daemon_comes_up(): void
    {
        $compose = $this->createMock(DockerCompose::class);
        $compose->method('isDockerRunning')->willReturn(false);

        $launcher = new StubDockerLauncher(
            $compose,
            $this->detector(Platform::Macos),
        );
        $launcher->launchSucceeds = true;
        $launcher->probeSucceeds = true;

        $this->assertTrue($launcher->ensureRunning($this->formatter()));
        $this->assertTrue($launcher->launchCalled);
    }

    public function test_returns_false_when_launch_fails(): void
    {
        $compose = $this->createMock(DockerCompose::class);
        $compose->method('isDockerRunning')->willReturn(false);

        $launcher = new StubDockerLauncher(
            $compose,
            $this->detector(Platform::Macos),
        );
        $launcher->launchSucceeds = false;

        $this->assertFalse($launcher->ensureRunning($this->formatter()));
        $this->assertTrue($launcher->launchCalled);
    }

    public function test_returns_false_when_daemon_never_becomes_ready(): void
    {
        $compose = $this->createMock(DockerCompose::class);
        $compose->method('isDockerRunning')->willReturn(false);

        $launcher = new StubDockerLauncher(
            $compose,
            $this->detector(Platform::Macos),
        );
        $launcher->launchSucceeds = true;
        $launcher->probeSucceeds = false;
        // A zero budget makes the poll loop exit immediately, simulating a
        // timeout without sleeping for the real 120s.
        $launcher->waitTimeout = 0;

        $this->assertFalse($launcher->ensureRunning($this->formatter()));
        $this->assertTrue($launcher->launchCalled);
    }

    public function test_timeout_message_mentions_seconds(): void
    {
        $compose = $this->createMock(DockerCompose::class);
        $compose->method('isDockerRunning')->willReturn(false);

        $launcher = new StubDockerLauncher(
            $compose,
            $this->detector(Platform::Macos),
        );
        $launcher->launchSucceeds = true;
        $launcher->probeSucceeds = false;
        $launcher->waitTimeout = 0;

        $output = new BufferedOutput();
        $formatter = new OutputFormatter($output);

        $this->assertFalse($launcher->ensureRunning($formatter));
        $this->assertStringContainsString('did not become ready', $output->fetch());
    }

    private function detector(Platform $platform): PlatformDetector
    {
        $detector = $this->createMock(PlatformDetector::class);
        $detector->method('detect')->willReturn($platform);

        return $detector;
    }

    private function formatter(): OutputFormatter
    {
        return new OutputFormatter(new BufferedOutput());
    }

    public function test_wsl_launch_addresses_cmd_exe_by_absolute_interop_path(): void
    {
        $launcher = new RecordingWslLauncher(
            $this->createMock(DockerCompose::class),
            ['/mnt/c/Windows/System32/cmd.exe'],
        );
        $launcher->succeedingBinaries = ['/mnt/c/Windows/System32/cmd.exe'];

        $this->assertTrue($launcher->launchWslForTest());
        $this->assertNotSame([], $launcher->commands);
        $this->assertSame('/mnt/c/Windows/System32/cmd.exe', $launcher->commands[0][0]);
    }

    public function test_wsl_launch_falls_back_to_powershell_when_cmd_exe_fails(): void
    {
        $powershell = '/mnt/c/Windows/System32/WindowsPowerShell/v1.0/powershell.exe';

        $launcher = new RecordingWslLauncher(
            $this->createMock(DockerCompose::class),
            ['/mnt/c/Windows/System32/cmd.exe', $powershell],
        );
        $launcher->succeedingBinaries = [$powershell];

        $this->assertTrue($launcher->launchWslForTest());

        $used = array_map(static fn (array $command): string => $command[0], $launcher->commands);
        $this->assertContains($powershell, $used);
    }

    public function test_wsl_launch_reports_failure_when_no_interop_binary_works(): void
    {
        $launcher = new RecordingWslLauncher(
            $this->createMock(DockerCompose::class),
            ['/mnt/c/Windows/System32/cmd.exe'],
        );
        $launcher->succeedingBinaries = [];

        $this->assertFalse($launcher->launchWslForTest());
    }

    public function test_wsl_launch_still_tries_bare_names_for_inherited_windows_path(): void
    {
        $launcher = new RecordingWslLauncher($this->createMock(DockerCompose::class), []);
        $launcher->succeedingBinaries = ['cmd.exe'];

        $launcher->launchWslForTest();

        $used = array_map(static fn (array $command): string => $command[0], $launcher->commands);
        $this->assertContains('cmd.exe', $used);
    }

    public function test_launch_attempt_treats_a_hanging_gui_launcher_as_launched(): void
    {
        $launcher = new class ($this->createMock(DockerCompose::class)) extends DockerLauncher {
            public function attempt(): bool
            {
                // `true` never exits on its own within the budget below.
                return $this->runLaunchCommand(['sleep', '30'], 1);
            }
        };

        $this->assertTrue($launcher->attempt());
    }

    public function test_launch_attempt_returns_false_when_the_binary_cannot_be_spawned(): void
    {
        $launcher = new class ($this->createMock(DockerCompose::class)) extends DockerLauncher {
            public function attempt(): bool
            {
                return $this->runLaunchCommand(['/nonexistent/ngramx-not-a-binary'], 5);
            }
        };

        $this->assertFalse($launcher->attempt());
    }
}
