<?php

declare(strict_types=1);

namespace Ngramx\Docker;

/**
 * The host platform ngramx is running on, narrowed to the variants that
 * matter for launching the Docker daemon.
 */
enum Platform: string
{
    case Macos = 'macos';
    case Windows = 'windows';
    case Wsl = 'wsl';
    case Linux = 'linux';
    case Unknown = 'unknown';

    /**
     * Whether ngramx knows how to start Docker automatically on this
     * platform. Unknown platforms fall back to the manual "please start
     * Docker" message.
     */
    public function canAutoStartDocker(): bool
    {
        return $this !== self::Unknown;
    }
}
