<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone;

use Ngramx\Postmaclone\Exception\PostmacloneException;

/**
 * Choose live vs idle database names for shared.ping_pong produce/connect.
 */
final class SharedSlotPicker
{
    /**
     * @param array<string, mixed>|null $manifest
     * @return array{live: string, idle: string}
     */
    public static function pick(?array $manifest, string $scratchDatabase, string $sharedDatabase): array
    {
        if ($scratchDatabase === '' || $sharedDatabase === '') {
            throw new PostmacloneException(
                'shared.ping_pong requires target.remote.database and shared.database'
            );
        }
        if ($scratchDatabase === $sharedDatabase) {
            throw new PostmacloneException(
                'shared.ping_pong requires two different database names'
            );
        }

        $slots = [$scratchDatabase, $sharedDatabase];
        $manifestLive = self::manifestLive($manifest);
        $live = $manifestLive !== null && in_array($manifestLive, $slots, true)
            ? $manifestLive
            : $sharedDatabase;
        $idle = $live === $scratchDatabase ? $sharedDatabase : $scratchDatabase;

        return ['live' => $live, 'idle' => $idle];
    }

    /**
     * @param array<string, mixed>|null $manifest
     */
    public static function manifestLive(?array $manifest): ?string
    {
        $value = $manifest['shared_database'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
