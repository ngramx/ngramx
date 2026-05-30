<?php

declare(strict_types=1);

namespace Ngramx\Config;

final class RecommendedCommands
{
    /** @var array<string, array{description: string, example: string}> */
    public const COMMANDS = [
        'clear' => [
            'description' => 'install deps and clear caches only (no DB changes — use fresh if the branch has schema or seed changes)',
            'example' => 'composer install && php artisan optimize:clear',
        ],
        'fresh' => [
            'description' => 'drop tables, re-migrate, re-seed, install deps, clear caches',
            'example' => 'composer install && php artisan migrate:fresh --seed && php artisan optimize:clear',
        ],
    ];

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::COMMANDS);
    }
}
