<?php

declare(strict_types=1);

namespace Ngramx\Console;

/**
 * Lets a namespaced command be typed with a space instead of a colon.
 *
 * Symfony Console names grouped commands `group:sub`, but `ngramx codabyte
 * login` is the form people reach for. This rewrites the raw argv so both
 * spellings reach the same command, and a bare group name lists what is in it
 * rather than failing as an unknown command.
 */
final class CommandGroupArgv
{
    /**
     * @param list<string> $argv Raw argv, including the script name at index 0.
     * @param list<string> $groups Recognised group names.
     *
     * @return list<string> The argv to hand to Symfony Console.
     */
    public static function rewrite(array $argv, array $groups): array
    {
        $index = self::commandIndex($argv);

        if ($index === null || !in_array($argv[$index], $groups, true)) {
            return $argv;
        }

        $group = $argv[$index];
        $next = $argv[$index + 1] ?? null;

        // `ngramx codabyte` on its own: show what the group contains.
        if ($next === null || str_starts_with($next, '-')) {
            return array_merge(
                array_slice($argv, 0, $index),
                ['list', $group],
                array_slice($argv, $index + 1)
            );
        }

        return array_merge(
            array_slice($argv, 0, $index),
            [$group . ':' . $next],
            array_slice($argv, $index + 2)
        );
    }

    /**
     * Index of the command name: the first token that is not the script name
     * and not a global option, so `ngramx -v codabyte login` still works.
     *
     * @param list<string> $argv
     */
    private static function commandIndex(array $argv): ?int
    {
        $count = count($argv);

        for ($i = 1; $i < $count; $i++) {
            if (!str_starts_with($argv[$i], '-')) {
                return $i;
            }
        }

        return null;
    }
}
