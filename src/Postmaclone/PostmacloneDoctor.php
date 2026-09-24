<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone;

use Ngramx\Codabyte\ServerTargetResolver;
use Ngramx\Config\Schema\NgramxConfig;
use Ngramx\Config\Schema\Postmaclone\BackupConfig;
use Ngramx\Config\Schema\Postmaclone\ConnectConfig;
use Ngramx\Postmaclone\Backup\OpAuthProbe;
use Ngramx\Postmaclone\Backup\S3Credentials;
use Ngramx\Postmaclone\Connect\SharedDbFreshnessChecker;
use Ngramx\Postmaclone\Connect\SshTunnelManager;
use Ngramx\Postmaclone\Connect\TrustedEgressDetector;
use Ngramx\Postmaclone\Connection\RemoteDbConnectionResolver;
use Ngramx\Postmaclone\EngineDetector;
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
     *   needs_s3: bool,
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
                    'message' => $config->postmacloneError !== null
                        ? 'Invalid postmaclone section: ' . $config->postmacloneError
                        : 'Missing postmaclone: section in ngramx.yml',
                    'blocking' => true,
                ]],
                'next_steps' => [],
                'suggestions' => [],
                'needs_s3' => false,
            ];
        }

        $checks = [];
        $needsS3 = $this->needsS3Credentials($config);
        $nextSteps = [];

        // op / Spaces credentials only matter for S3 backup or prebuilt artifact sources.
        if ($needsS3) {
            $auth = (new OpAuthProbe())->probe();
            $nextSteps = $auth['next_steps'];
            $credentialRefs = $pm->backup->credentials ?? $pm->prebuilt?->credentials;
            foreach (S3Credentials::doctorChecks($credentialRefs) as $check) {
                $checks[] = [
                    'ok' => $check['ok'],
                    'message' => $check['message'],
                    'blocking' => !$check['ok'],
                ];
            }

            if ($credentialRefs !== null && $auth['signed_in']) {
                try {
                    (new S3Credentials($credentialRefs))->require();
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
        }

        $restore = (new RestoreDoctor())->analyse($pm, $projectRoot, $fromPath);
        foreach ($restore['checks'] as $check) {
            $checks[] = [
                'ok' => $check['ok'],
                'message' => $check['message'],
                'blocking' => !$check['ok'],
            ];
        }

        if ($pm->hasShared()) {
            foreach ($this->connectChecks($config, $projectRoot) as $check) {
                $checks[] = $check;
            }
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
            'next_steps' => $nextSteps,
            'suggestions' => $restore['suggestions'],
            'needs_s3' => $needsS3,
        ];
    }

    public function needsS3Credentials(NgramxConfig $config): bool
    {
        $pm = $config->postmaclone;
        if ($pm === null) {
            return false;
        }

        if ($pm->backup->source === BackupConfig::SOURCE_S3) {
            return true;
        }

        if ($this->isObjectStorageUri($pm->backup->path)) {
            return true;
        }

        if ($pm->hasPrebuilt()) {
            $prebuilt = $pm->prebuilt;
            if ($prebuilt === null) {
                return false;
            }
            if ($prebuilt->source === BackupConfig::SOURCE_S3 || $this->isObjectStorageUri($prebuilt->path)) {
                return true;
            }
        }

        return false;
    }

    private function isObjectStorageUri(?string $uri): bool
    {
        return is_string($uri) && (
            str_starts_with($uri, 's3://')
            || str_starts_with($uri, 'spaces://')
        );
    }

    /**
     * @return list<DoctorCheck>
     */
    private function connectChecks(NgramxConfig $config, string $projectRoot): array
    {
        $pm = $config->postmaclone;
        if ($pm === null || !$pm->hasShared()) {
            return [];
        }

        $checks = [];
        $direct = (new TrustedEgressDetector())->isDirectMode();
        $checks[] = [
            'ok' => true,
            'message' => 'Shared hosted DB configured'
                . ($direct ? ' (direct egress — Codabyte/trusted host)' : ' (SSH tunnel via Codabyte)'),
            'blocking' => false,
        ];

        $auth = (new OpAuthProbe())->probe();
        if (!$auth['signed_in']) {
            $checks[] = [
                'ok' => false,
                'message' => '1Password not ready for shared DB credentials',
                'blocking' => true,
            ];
            foreach ($auth['next_steps'] as $step) {
                $checks[] = ['ok' => false, 'message' => $step, 'blocking' => false];
            }

            return $checks;
        }

        try {
            $engine = (new EngineDetector())->detect($pm->engine, $config->docker->composeFile);
            (new RemoteDbConnectionResolver())->resolve($pm->shared?->connection, $engine);
            $checks[] = [
                'ok' => true,
                'message' => 'Resolved shared DB credentials via `op read` (values not printed)',
                'blocking' => false,
            ];
        } catch (PostmacloneException $e) {
            $checks[] = [
                'ok' => false,
                'message' => 'Could not resolve shared DB connection: ' . $e->getMessage(),
                'blocking' => true,
            ];
        }

        if (!$direct) {
            $connectConfig = $pm->connect ?? new ConnectConfig();
            $target = ServerTargetResolver::fromEnvironment()->resolve([
                'host' => $connectConfig->tunnelHost,
                'ssh-user' => $connectConfig->tunnelUser,
                'port' => $connectConfig->tunnelPort !== null ? (string) $connectConfig->tunnelPort : null,
            ]);
            $tunnel = new SshTunnelManager($target);
            $reach = $tunnel->probeReachability();
            $checks[] = [
                'ok' => $reach['ok'],
                'message' => $reach['message'],
                'blocking' => !$reach['ok'],
            ];
            $batch = $tunnel->probeBatchMode();
            $checks[] = [
                'ok' => $batch['ok'],
                'message' => $batch['message'],
                'blocking' => false,
            ];
        }

        foreach ((new SharedDbFreshnessChecker())->check($pm->prebuilt, $pm->shared?->maxAgeHours, false) as $warning) {
            $checks[] = ['ok' => false, 'message' => $warning, 'blocking' => false];
        }

        $checks[] = [
            'ok' => true,
            'message' => 'Connect with: ngramx postmaclone connect',
            'blocking' => false,
        ];

        return $checks;
    }
}
