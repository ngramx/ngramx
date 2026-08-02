<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone;

use Ngramx\Config\Schema\NgramxConfig;
use Ngramx\Config\Schema\Postmaclone\BackupConfig;
use Ngramx\Postmaclone\Backup\OpAuthProbe;
use Ngramx\Postmaclone\Backup\S3Credentials;
use Ngramx\Postmaclone\Exception\PostmacloneException;
use Ngramx\Postmaclone\Restore\RestoreDoctor;

/**
 * Shared readiness checks for `ngramx postmaclone doctor` and `ngramx up --postmaclone`.
 *
 * @phpstan-type DoctorCheck array{ok: bool, message: string, blocking: bool}
 */
final class PostmacloneDoctor
{
    /**
     * @return array{
     *   ok: bool,
     *   checks: list<DoctorCheck>,
     *   next_steps: list<string>,
     *   suggestions: list<string>,
     * }
     */
    public function diagnose(NgramxConfig $config, string $projectRoot, ?string $fromPath = null): array
    {
        $pm = $config->postmaclone;
        if ($pm === null) {
            return [
                'ok' => false,
                'checks' => [[
                    'ok' => false,
                    'message' => 'Missing postmaclone: section in ngramx.yml',
                    'blocking' => true,
                ]],
                'next_steps' => [],
                'suggestions' => [],
            ];
        }

        $checks = [];
        $needsS3 = $pm->backup->source === BackupConfig::SOURCE_S3
            || (is_string($pm->backup->path) && (
                str_starts_with($pm->backup->path, 's3://')
                || str_starts_with($pm->backup->path, 'spaces://')
            ));

        $auth = (new OpAuthProbe())->probe();
        foreach (S3Credentials::doctorChecks($pm->backup->credentials) as $check) {
            $blocking = $needsS3 && !$check['ok'];
            $checks[] = [
                'ok' => $check['ok'],
                'message' => $needsS3 || $check['ok']
                    ? $check['message']
                    : '(optional) ' . $check['message'],
                'blocking' => $blocking,
            ];
        }

        if ($needsS3 && $pm->backup->credentials !== null && $auth['signed_in']) {
            try {
                (new S3Credentials($pm->backup->credentials))->require();
                $checks[] = [
                    'ok' => true,
                    'message' => 'Resolved credentials via `op read` (values not printed)',
                    'blocking' => false,
                ];
            } catch (PostmacloneException $e) {
                $checks[] = [
                    'ok' => false,
                    'message' => 'Could not resolve credential refs: ' . $e->getMessage(),
                    'blocking' => true,
                ];
            }
        }

        $restore = (new RestoreDoctor())->analyse($pm, $projectRoot, $fromPath);
        foreach ($restore['checks'] as $check) {
            $checks[] = [
                'ok' => $check['ok'],
                'message' => $check['message'],
                'blocking' => !$check['ok'],
            ];
        }

        $ok = true;
        foreach ($checks as $check) {
            if ($check['blocking']) {
                $ok = false;
                break;
            }
        }

        return [
            'ok' => $ok,
            'checks' => $checks,
            'next_steps' => $auth['next_steps'],
            'suggestions' => $restore['suggestions'],
        ];
    }
}
