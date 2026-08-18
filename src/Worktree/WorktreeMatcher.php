<?php

declare(strict_types=1);

namespace Ngramx\Worktree;

/**
 * Matches a free-text argument against the worktrees a repository has, so
 * `--cleanup <text>` accepts whatever the developer has to hand: the folder
 * name, the Docker namespace, a ticket reference, or a fragment of the branch.
 *
 * Pure and side-effect free — the caller decides what to do when more than one
 * worktree matches (prompt) or none does (report).
 */
final class WorktreeMatcher
{
    /**
     * Find the worktrees matching $needle, most-specific strategy first: an
     * exact folder/namespace name beats a ticket-slug match, which beats a
     * loose substring. The first strategy that matches anything wins, so
     * "gig-2345" does not also drag in "gig-23450".
     *
     * @param list<string> $worktreePaths Absolute paths
     * @param array<string, string> $branchMap Worktree path => branch name
     * @return list<string> Matching paths, in the order given
     */
    public static function match(
        array $worktreePaths,
        array $branchMap,
        string $needle,
        string $defaultTeam = ''
    ): array {
        $needle = trim($needle);
        if ($needle === '') {
            return [];
        }

        $lowerNeedle = mb_strtolower($needle);

        $strategies = [
            // Exact folder name or exact Docker namespace.
            static function (string $path) use ($lowerNeedle): bool {
                $folder = mb_strtolower(basename($path));

                return $folder === $lowerNeedle
                    || WorktreeIdentity::namespaceFor($folder) === $lowerNeedle;
            },
        ];

        // A ticket reference ("2345", "gig-2345", "gig2345", or a pasted branch
        // name) targets the worktree folder that ticket created.
        $ticketSlug = WorktreeIdentity::normalizeTicket($needle, $defaultTeam);
        if ($ticketSlug !== '') {
            $strategies[] = static function (string $path) use ($ticketSlug): bool {
                $folder = mb_strtolower(basename($path));

                return $folder === $ticketSlug || str_starts_with($folder, $ticketSlug . '-');
            };
        }

        // Folder names come from the branch, which may lack the team prefix
        // ("2478-fix" => folder "2478-<repo>"), so a ticket reference has to
        // reach the folder by its bare number too. Tried after the full slug,
        // which stays the more specific match.
        if (preg_match('/^[a-z]+-(\d+)$/', $ticketSlug, $ticketParts) === 1) {
            $number = $ticketParts[1];
            $strategies[] = static function (string $path) use ($number): bool {
                $folder = mb_strtolower(basename($path));

                return $folder === $number || str_starts_with($folder, $number . '-');
            };
        }

        // Anything else: a fragment of the folder, the namespace or the branch.
        $strategies[] = static function (string $path) use ($lowerNeedle, $branchMap): bool {
            $folder = mb_strtolower(basename($path));
            $branch = mb_strtolower($branchMap[$path] ?? '');

            return str_contains($folder, $lowerNeedle)
                || str_contains(WorktreeIdentity::namespaceFor($folder), $lowerNeedle)
                || ($branch !== '' && str_contains($branch, $lowerNeedle));
        };

        foreach ($strategies as $matches) {
            $found = array_values(array_filter($worktreePaths, $matches));
            if ($found !== []) {
                return $found;
            }
        }

        return [];
    }
}
