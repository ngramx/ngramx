<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Backup;

/**
 * Host/runtime detection for environment-specific guidance (e.g. WSL vs native Linux/macOS).
 */
final class HostEnvironment
{
    public static function isWsl(): bool
    {
        if (self::env('WSL_DISTRO_NAME') !== '' || self::env('WSL_INTEROP') !== '') {
            return true;
        }

        if (is_file('/proc/sys/fs/binfmt_misc/WSLInterop')) {
            return true;
        }

        if (is_readable('/proc/version')) {
            $version = @file_get_contents('/proc/version');
            if (is_string($version) && stripos($version, 'microsoft') !== false) {
                return true;
            }
        }

        return false;
    }

    private static function env(string $name): string
    {
        $value = getenv($name);

        return is_string($value) ? $value : '';
    }
}
