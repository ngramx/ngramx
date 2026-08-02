<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Restore;

use Ngramx\Config\Schema\Postmaclone\BackupConfig;
use Ngramx\Config\Schema\Postmaclone\PostmacloneConfig;
use Ngramx\Postmaclone\Connection\PdoDriverGuard;
use Symfony\Component\Process\Process;

/**
 * Restore-focused checks for `ngramx postmaclone doctor`.
 *
 * @phpstan-type DoctorCheck array{ok: bool, message: string}
 */
final class RestoreDoctor
{
    /**
     * @return array{
     *   checks: list<DoctorCheck>,
     *   suggestions: list<string>,
     *   dump_path: ?string
     * }
     */
    public function analyse(PostmacloneConfig $pm, string $projectRoot, ?string $fromPath = null): array
    {
        $checks = [];
        $suggestions = [];
        $backup = $pm->backup;

        $engine = $pm->engine ?? PostmacloneConfig::ENGINE_POSTGRES;
        $pdoCheck = PdoDriverGuard::doctorCheck($engine);
        $checks[] = $pdoCheck;
        if (!$pdoCheck['ok']) {
            $suggestions[] = $pdoCheck['message'];
        }

        $checks[] = [
            'ok' => true,
            'message' => 'Plain-SQL restores strip prod roles/ACLs and reassign ownership to the clone login (like pg_restore -O --no-acl)',
        ];

        if ($backup->roles !== null) {
            $checks[] = [
                'ok' => true,
                'message' => 'backup.roles is set but ignored — prod roles are not replayed into the clone',
            ];
            $suggestions[] = 'You can remove postmaclone.backup.roles from ngramx.yml; it is no longer used.';
        }

        if ($this->needsDumpBasename($backup) && ($backup->file === null || trim($backup->file) === '')) {
            $checks[] = [
                'ok' => false,
                'message' => 'S3/Spaces path looks like a shared daily prefix but backup.file is missing',
            ];
            $suggestions[] = 'Set the project dump basename, e.g.:';
            $suggestions[] = '  file: "earl_kendrick_prod.sql.gz"';
        }

        $psql = $this->psqlVersion();
        if ($psql !== null) {
            $checks[] = [
                'ok' => true,
                'message' => "Host psql: {$psql['version']}",
            ];
            if ($psql['major'] < 17) {
                $suggestions[] = 'Host psql is older than PG 17 — dumps with \\restrict are sanitized automatically on restore.';
            }
        } else {
            $checks[] = [
                'ok' => false,
                'message' => 'psql not found on PATH (used for host readiness probes; Docker restores use docker exec)',
            ];
        }

        $dumpPath = $this->findDump($projectRoot, $backup, $fromPath);
        if ($dumpPath === null) {
            $checks[] = [
                'ok' => true,
                'message' => 'No local/cached dump present yet (optional for doctor)',
            ];
        } else {
            $checks[] = [
                'ok' => true,
                'message' => 'Cached dump: ' . $dumpPath,
            ];
            if ($this->looksLikeCustomFormat($dumpPath)) {
                $checks[] = [
                    'ok' => true,
                    'message' => 'Custom-format dump — restored with pg_restore --no-owner --no-acl',
                ];
            } elseif ($this->dumpHasRestrict($dumpPath) && ($psql['major'] ?? 99) < 17) {
                $checks[] = [
                    'ok' => true,
                    'message' => 'Dump contains \\restrict — will be stripped for restore automatically',
                ];
            }
        }

        return [
            'checks' => $checks,
            'suggestions' => $suggestions,
            'dump_path' => $dumpPath,
        ];
    }

    private function needsDumpBasename(BackupConfig $backup): bool
    {
        $path = (string) $backup->path;

        return $backup->source === BackupConfig::SOURCE_S3
            && (str_ends_with($path, '/') || str_contains($path, '*'));
    }

    private function findDump(string $projectRoot, BackupConfig $backup, ?string $fromPath): ?string
    {
        if (is_string($fromPath) && is_file($fromPath)) {
            return $fromPath;
        }

        if ($backup->source === BackupConfig::SOURCE_LOCAL && is_string($backup->path)) {
            $local = $backup->path;
            if (!str_starts_with($local, '/')) {
                $local = rtrim($projectRoot, '/') . '/' . ltrim($local, './');
            }
            if (is_file($local)) {
                return $local;
            }
        }

        $cache = rtrim($projectRoot, '/') . '/.ngramx/cache';
        if (!is_dir($cache)) {
            return null;
        }

        $candidates = glob($cache . '/postmaclone-*.dump*') ?: [];
        $candidates = array_values(array_filter(
            $candidates,
            static fn (string $p): bool => is_file($p)
                && !str_ends_with($p, '.sanitized')
                && !str_ends_with($p, '.roles.sql')
                && !str_ends_with($p, '.norestrict')
        ));
        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $newest = $candidates[0];
        $ungz = $newest . '.ungz';
        if (is_file($ungz)) {
            return $ungz;
        }

        return $newest;
    }

    /**
     * @return array{version: string, major: int}|null
     */
    private function psqlVersion(): ?array
    {
        $process = new Process(['psql', '--version']);
        $process->setTimeout(10);
        $process->run();
        if (!$process->isSuccessful()) {
            return null;
        }
        $out = trim($process->getOutput());
        if (!preg_match('/(\d+)\.(\d+)/', $out, $m)) {
            return ['version' => $out, 'major' => 0];
        }

        return ['version' => $out, 'major' => (int) $m[1]];
    }

    private function looksLikeCustomFormat(string $path): bool
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $magic = fread($fh, 5);
        fclose($fh);

        return is_string($magic) && str_starts_with($magic, 'PGDMP');
    }

    private function dumpHasRestrict(string $path): bool
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $head = fread($fh, 8192);
        fclose($fh);

        return is_string($head) && str_contains($head, '\\restrict');
    }
}
