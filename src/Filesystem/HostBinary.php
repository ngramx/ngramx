<?php

declare(strict_types=1);

namespace Ngramx\Filesystem;

use Symfony\Component\Process\ExecutableFinder;

final class HostBinary
{
    public static function find(string $name): ?string
    {
        return (new ExecutableFinder())->find($name);
    }

    public static function exists(string $name): bool
    {
        return self::find($name) !== null;
    }
}
