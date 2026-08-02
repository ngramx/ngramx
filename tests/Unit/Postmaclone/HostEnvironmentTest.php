<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Postmaclone\Backup\HostEnvironment;
use PHPUnit\Framework\TestCase;

final class HostEnvironmentTest extends TestCase
{
    public function testIsWslMatchesProcOrEnvWhenPresent(): void
    {
        $fromEnv = getenv('WSL_DISTRO_NAME') || getenv('WSL_INTEROP');
        $fromProc = is_readable('/proc/version')
            && is_string($v = @file_get_contents('/proc/version'))
            && stripos($v, 'microsoft') !== false;

        if ($fromEnv || $fromProc || is_file('/proc/sys/fs/binfmt_misc/WSLInterop')) {
            self::assertTrue(HostEnvironment::isWsl());
        } else {
            self::assertFalse(HostEnvironment::isWsl());
        }
    }
}
