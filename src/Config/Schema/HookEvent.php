<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema;

/**
 * Named lifecycle events that can trigger configured host commands.
 */
enum HookEvent: string
{
    case WorktreeCreate = 'onWorktreeCreate';
    case EnvironmentUp = 'onEnvironmentUp';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $event): string => $event->value,
            self::cases(),
        );
    }
}
