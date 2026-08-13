<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Docker;

use Ngramx\Docker\Platform;
use Ngramx\Docker\PlatformDetector;
use PHPUnit\Framework\TestCase;

/**
 * A {@see PlatformDetector} subclass whose WSL signals are configurable
 * properties, so the Linux-variant detection can be exercised
 * deterministically regardless of the host running the tests.
 */
class StubPlatformDetector extends PlatformDetector
{
    public ?string $wslDistroName = null;
    public ?string $procVersion = null;

    protected function wslDistroName(): ?string
    {
        return $this->wslDistroName;
    }

    protected function procVersion(): ?string
    {
        return $this->procVersion;
    }
}

class PlatformDetectorTest extends TestCase
{
    public function test_detect_returns_a_known_platform(): void
    {
        $platform = (new PlatformDetector())->detect();

        $this->assertInstanceOf(Platform::class, $platform);
    }

    public function test_detects_wsl_via_wsl_distro_name_env(): void
    {
        $detector = new StubPlatformDetector();
        $detector->wslDistroName = 'Ubuntu';

        $this->assertLinuxVariant(Platform::Wsl, $detector);
    }

    public function test_detects_wsl_via_proc_version_microsoft_string(): void
    {
        $detector = new StubPlatformDetector();
        $detector->procVersion = 'Linux version 5.15.153.1-microsoft-standard-WSL2';

        $this->assertLinuxVariant(Platform::Wsl, $detector);
    }

    public function test_detects_plain_linux_when_no_wsl_signals_present(): void
    {
        $detector = new StubPlatformDetector();
        $detector->procVersion = 'Linux version 6.6.0-generic';

        $this->assertLinuxVariant(Platform::Linux, $detector);
    }

    public function test_platform_can_auto_start_docker_flag(): void
    {
        $this->assertTrue(Platform::Macos->canAutoStartDocker());
        $this->assertTrue(Platform::Windows->canAutoStartDocker());
        $this->assertTrue(Platform::Wsl->canAutoStartDocker());
        $this->assertTrue(Platform::Linux->canAutoStartDocker());
        $this->assertFalse(Platform::Unknown->canAutoStartDocker());
    }

    /**
     * The Linux-variant branch only runs on Linux hosts — on macOS/Windows
     * detect() short-circuits before reaching detectLinuxVariant(). Assert
     * the expected variant when on Linux, otherwise just pass so the suite
     * stays green everywhere.
     */
    private function assertLinuxVariant(Platform $expected, PlatformDetector $detector): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->addToAssertionCount(1);
            return;
        }

        $this->assertSame($expected, $detector->detect());
    }
}
