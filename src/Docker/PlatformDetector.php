<?php

declare(strict_types=1);

namespace Ngramx\Docker;

/**
 * Detects the host platform, distinguishing a plain Linux install from
 * Windows Subsystem for Linux (WSL). The distinction matters because on
 * WSL the Docker engine almost always lives in Docker Desktop on the
 * Windows side, so it has to be launched from Windows rather than via a
 * Linux systemd unit.
 */
class PlatformDetector
{
    public function detect(): Platform
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => Platform::Macos,
            'Windows' => Platform::Windows,
            'Linux' => $this->detectLinuxVariant(),
            default => Platform::Unknown,
        };
    }

    private function detectLinuxVariant(): Platform
    {
        // Docker Desktop sets WSL_DISTRO_NAME in every shell it opens.
        if ($this->wslDistroName() !== null) {
            return Platform::Wsl;
        }

        // Older / non-Docker-Desktop WSL setups still advertise themselves
        // in /proc/version (e.g. "...Microsoft...").
        $version = $this->procVersion();
        if ($version !== null && stripos($version, 'microsoft') !== false) {
            return Platform::Wsl;
        }

        return Platform::Linux;
    }

    /**
     * Overridable hook for tests. Returns the WSL_DISTRO_NAME env value or
     * null when unset.
     */
    protected function wslDistroName(): ?string
    {
        $value = getenv('WSL_DISTRO_NAME');

        return $value === false ? null : $value;
    }

    /**
     * Overridable hook for tests. Returns the contents of /proc/version or
     * null when it can't be read.
     */
    protected function procVersion(): ?string
    {
        $version = @file_get_contents('/proc/version');

        return $version === false ? null : $version;
    }
}
