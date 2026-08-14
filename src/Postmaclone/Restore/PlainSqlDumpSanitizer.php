<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Restore;

use Ngramx\Postmaclone\Exception\PostmacloneException;

/**
 * Prepares plain-text pg_dump scripts like `pg_restore --no-owner --no-acl`:
 * drop prod roles/ACLs/DB framing and make the clone login own everything.
 *
 * No stub roles and no dump-filename role guessing — the ephemeral user
 * (e.g. postmaclone) is the only principal that matters.
 */
final class PlainSqlDumpSanitizer
{
    public function forPsql(string $dumpPath): string
    {
        $outPath = $dumpPath . '.sanitized';
        $in = fopen($dumpPath, 'rb');
        if ($in === false) {
            throw new PostmacloneException("Failed to read dump: {$dumpPath}");
        }
        $out = fopen($outPath, 'wb');
        if ($out === false) {
            fclose($in);
            throw new PostmacloneException("Failed to write sanitized dump: {$outPath}");
        }

        try {
            while (($line = fgets($in)) !== false) {
                $rewritten = $this->rewriteLine($line);
                if ($rewritten === null) {
                    continue;
                }
                if (fwrite($out, $rewritten) === false) {
                    throw new PostmacloneException("Failed while writing sanitized dump: {$outPath}");
                }
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        return $outPath;
    }

    public function cleanup(string $originalPath, string $maybeSanitizedPath): void
    {
        if ($maybeSanitizedPath !== $originalPath && is_file($maybeSanitizedPath)) {
            @unlink($maybeSanitizedPath);
        }
        foreach ([$originalPath . '.norestrict', $originalPath . '.roles.sql'] as $legacy) {
            if (is_file($legacy)) {
                @unlink($legacy);
            }
        }
    }

    /**
     * @return string|null Null drops the line.
     */
    public function rewriteLine(string $line): ?string
    {
        if ($this->shouldDropLine($line)) {
            return null;
        }

        $rewritten = preg_replace(
            '/\bOWNER TO\s+(?:"[^"]+"|[A-Za-z0-9_]+)/i',
            'OWNER TO CURRENT_USER',
            $line
        ) ?? $line;

        return preg_replace(
            '/\bAUTHORIZATION\s+(?:"[^"]+"|[A-Za-z0-9_]+)/i',
            'AUTHORIZATION CURRENT_USER',
            $rewritten
        ) ?? $rewritten;
    }

    public function shouldDropLine(string $line): bool
    {
        $trimmed = rtrim($line, "\r\n");

        return $this->isRestrictMetaCommand($trimmed)
            || $this->isConnectMetaCommand($trimmed)
            || $this->isDatabaseAdminStatement($trimmed)
            || $this->isPrivilegeStatement($trimmed)
            || $this->isDefaultPrivilegesStatement($trimmed)
            || $this->isRoleStatement($trimmed)
            || $this->isSessionRoleStatement($trimmed)
            || $this->isPolicyStatement($trimmed);
    }

    public function isRestrictMetaCommand(string $line): bool
    {
        return (bool) preg_match('/^\\\\(un)?restrict\s+[A-Za-z0-9]+\s*$/', rtrim($line, "\r\n"));
    }

    public function isConnectMetaCommand(string $line): bool
    {
        return (bool) preg_match('/^\s*\\\\c(onnect)?(\s|$)/', rtrim($line, "\r\n"));
    }

    public function isDatabaseAdminStatement(string $line): bool
    {
        return (bool) preg_match('/^\s*(CREATE|DROP|ALTER)\s+DATABASE\b/i', rtrim($line, "\r\n"));
    }

    public function isPrivilegeStatement(string $line): bool
    {
        return (bool) preg_match('/^\s*(GRANT|REVOKE)\b/i', rtrim($line, "\r\n"));
    }

    public function isDefaultPrivilegesStatement(string $line): bool
    {
        return (bool) preg_match('/^\s*ALTER\s+DEFAULT\s+PRIVILEGES\b/i', rtrim($line, "\r\n"));
    }

    public function isRoleStatement(string $line): bool
    {
        return (bool) preg_match('/^\s*(CREATE|ALTER|DROP)\s+ROLE\b/i', rtrim($line, "\r\n"));
    }

    public function isSessionRoleStatement(string $line): bool
    {
        return (bool) preg_match('/^\s*SET\s+(ROLE|SESSION\s+AUTHORIZATION)\b/i', rtrim($line, "\r\n"));
    }

    public function isPolicyStatement(string $line): bool
    {
        return (bool) preg_match('/^\s*(CREATE|ALTER|DROP)\s+POLICY\b/i', rtrim($line, "\r\n"));
    }
}
