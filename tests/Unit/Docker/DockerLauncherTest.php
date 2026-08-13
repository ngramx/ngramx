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
}
