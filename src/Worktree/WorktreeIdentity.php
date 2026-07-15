<?php

declare(strict_types=1);

namespace Ngramx\Worktree;

/**
 * Pure helpers for deriving the identity of a per-ticket worktree: its slug,
 * folder name, Docker namespace and dev URL. Kept side-effect free so the
 * naming rules can be unit tested without touching git or Docker.
 */
class WorktreeIdentity
{
    private const MAX_NAMESPACE_LENGTH = 63;

    /**
     * Derive a normalised ticket slug (e.g. "gig-178") from the selected branch
     * name, falling back to the raw ticket argument when the branch does not
     * start with a recognisable "<team>-<number>" prefix.
     */
    public static function deriveTicketSlug(string $branch, string $fallbackTicket): string
    {
        if (preg_match('/^([a-z]+-\d+)/i', $branch, $matches) === 1) {
            return strtolower($matches[1]);
        }

        $fallback = self::sanitizeSegment($fallbackTicket);

        return $fallback !== '' ? $fallback : 'ticket';
    }

    /**
     * Normalise a user-supplied ticket identifier into a canonical
     * "<team>-<number>" slug:
     *
     *   - "2345"      => "gig-2345" (bare numbers get the default team prefix)
     *   - "gig-2345"  => "gig-2345"
     *   - "gig2345"   => "gig-2345" (missing hyphen restored)
     *   - "GIG-2345"  => "gig-2345"
     *
     * Anything that doesn't look like a ticket reference is sanitised as-is so
     * the caller can still use it for branch searching and folder naming.
     */
    public static function normalizeTicket(string $ticket, string $defaultTeam): string
    {
        $ticket = strtolower(trim($ticket));

        if (preg_match('/^\d+$/', $ticket) === 1) {
            return self::sanitizeSegment($defaultTeam) . '-' . $ticket;
        }

        if (preg_match('/^([a-z]+)-?(\d+)$/', $ticket, $matches) === 1) {
            return $matches[1] . '-' . $matches[2];
        }

        $sanitized = self::sanitizeSegment($ticket);

        return $sanitized !== '' ? $sanitized : 'ticket';
    }

    /**
     * Lowercase a path/segment and collapse anything that is not a-z0-9 into a
     * single hyphen so it is safe to use in folder names and Docker namespaces.
     */
    public static function sanitizeSegment(string $segment): string
    {
        $sanitized = preg_replace('/[^a-z0-9]+/', '-', strtolower($segment)) ?? '';

        return trim($sanitized, '-');
    }

    /**
     * The leaf folder name for the worktree, e.g. "gig-178-ill-kendrick".
     * This is what Cursor shows in the window title bar.
     */
    public static function folderName(string $ticketSlug, string $repoName): string
    {
        return $ticketSlug . '-' . self::sanitizeSegment($repoName);
    }

    /**
     * Docker Compose project name / namespace for the worktree env. Prefixed so
     * containers are clearly Ngramx-managed and truncated to Docker's 63 char limit.
     */
    public static function namespaceFor(string $folderName): string
    {
        $namespace = 'ngramx-' . self::sanitizeSegment($folderName);

        if (strlen($namespace) > self::MAX_NAMESPACE_LENGTH) {
            $namespace = rtrim(substr($namespace, 0, self::MAX_NAMESPACE_LENGTH), '-');
        }

        return $namespace;
    }
}
