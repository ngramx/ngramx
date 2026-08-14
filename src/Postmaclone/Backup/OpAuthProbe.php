<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Backup;

use Symfony\Component\Process\Process;

/**
 * Inspects local 1Password CLI auth state for doctor / error guidance.
 *
 * Does not collect passwords. On WSL, interactive `op account add` / `eval $(op signin)`
 * is the human path (desktop-app CLI integration is not assumed). Agents/CI should use
 * OP_SERVICE_ACCOUNT_TOKEN or inject POSTMACLONE_S3_* directly.
 */
final class OpAuthProbe
{
    public const SIGNIN_ADDRESS = 'gigabytesoftware.1password.com';

    /**
     * @return array{
     *   installed: bool,
     *   service_account: bool,
     *   account_configured: bool,
     *   signed_in: bool,
     *   wsl: bool,
     *   account_shorthands: list<string>,
     *   checks: list<array{ok: bool, message: string}>,
     *   next_steps: list<string>
     * }
     */
    public function probe(): array
    {
        $installed = S3Credentials::isOpAvailable();
        $serviceAccount = self::env('OP_SERVICE_ACCOUNT_TOKEN') !== '';
        $wsl = HostEnvironment::isWsl();
        $shorthands = [];
        $accountConfigured = false;
        $signedIn = false;
        $checks = [];
        $next = [];

        if ($wsl) {
            $checks[] = [
                'ok' => true,
                'message' => 'Running under WSL — use shell sign-in for 1Password (not desktop-app CLI integration)',
            ];
        }

        if (!$installed) {
            $checks[] = [
                'ok' => false,
                'message' => '1Password CLI (op) not found — install from ' . S3Credentials::OP_INSTALL_URL,
            ];
            $next[] = 'In this WSL/Linux distro: install op, then re-run: ngramx postmaclone doctor';
            if ($wsl) {
                $next = array_merge($next, self::wslHumanSetupSteps(includeAccountAdd: true));
            }

            return $this->result($installed, $serviceAccount, $accountConfigured, $signedIn, $wsl, $shorthands, $checks, $next);
        }

        $checks[] = ['ok' => true, 'message' => '1Password CLI (op) is on PATH'];

        if ($serviceAccount) {
            $checks[] = [
                'ok' => true,
                'message' => 'OP_SERVICE_ACCOUNT_TOKEN is set (non-interactive / agent path)',
            ];
            $whoami = $this->runOp(['whoami']);
            $signedIn = $whoami['ok'];
            $accountConfigured = true;
            $checks[] = [
                'ok' => $signedIn,
                'message' => $signedIn
                    ? 'Service account session usable (`op whoami` ok)'
                    : 'Service account token present but `op whoami` failed — check token/vault access',
            ];
            if (!$signedIn && $whoami['error'] !== '') {
                $next[] = $whoami['error'];
            }

            return $this->result($installed, $serviceAccount, $accountConfigured, $signedIn, $wsl, $shorthands, $checks, $next);
        }

        $shorthands = $this->listAccountShorthands();
        $accountConfigured = $shorthands !== [];

        $checks[] = [
            'ok' => $accountConfigured,
            'message' => $accountConfigured
                ? 'op account configured: ' . implode(', ', $shorthands)
                : 'No op account configured yet',
        ];

        if (!$accountConfigured) {
            $next = array_merge($next, $wsl
                ? self::wslHumanSetupSteps(includeAccountAdd: true)
                : self::nativeHumanSetupSteps(includeAccountAdd: true));
            $next[] = 'Agents/CI: admin creates a service account with read on Tech Team Vault → OP_SERVICE_ACCOUNT_TOKEN';
            $next[] = 'Docs: ' . S3Credentials::OP_SERVICE_ACCOUNTS_URL;

            return $this->result($installed, $serviceAccount, $accountConfigured, $signedIn, $wsl, $shorthands, $checks, $next);
        }

        $whoami = $this->runOp(['whoami']);
        $signedIn = $whoami['ok'];
        $checks[] = [
            'ok' => $signedIn,
            'message' => $signedIn
                ? 'Signed in (`op whoami` ok)'
                : 'Not signed in — no active op session',
        ];

        if (!$signedIn) {
            if ($wsl) {
                $next[] = 'WSL: sign in for this shell (ngramx will not collect your password):';
                $next[] = '  eval $(op signin)';
            } else {
                $next[] = 'Human (recommended): unlock the 1Password desktop app with CLI integration enabled, then retry.';
                $next[] = 'Human (fallback): eval $(op signin)';
            }
            $next[] = 'Agent/CI: export OP_SERVICE_ACCOUNT_TOKEN=… (no interactive sign-in)';
            $next[] = 'Agent/CI alternative: export POSTMACLONE_S3_KEY/SECRET directly (skip op://)';
            if ($whoami['error'] !== '') {
                $next[] = 'Detail: ' . $whoami['error'];
            }
        }

        return $this->result($installed, $serviceAccount, $accountConfigured, $signedIn, $wsl, $shorthands, $checks, $next);
    }

    public static function authGuidanceForError(string $opError): string
    {
        $lower = strtolower($opError);
        $lines = [];
        $wsl = HostEnvironment::isWsl();

        if (str_contains($lower, 'no active session') || str_contains($lower, 'not currently signed in')) {
            $lines[] = '1Password CLI has no active session.';
            if ($wsl) {
                $lines[] = '  WSL: eval $(op signin)   # then retry; ngramx does not accept your 1Password password';
            } else {
                $lines[] = '  Human: unlock the desktop app (CLI integration) or run: eval $(op signin)';
            }
            $lines[] = '  Agent/CI: set OP_SERVICE_ACCOUNT_TOKEN, or set POSTMACLONE_S3_KEY/SECRET directly';
        } elseif (str_contains($lower, 'account is not recognized') || str_contains($lower, 'no accounts configured')) {
            $lines[] = '1Password account may be missing. Run: op account add';
            $lines[] = '  Sign-in address: ' . self::SIGNIN_ADDRESS;
            if ($wsl) {
                $lines[] = '  Then: eval $(op signin)';
            }
        }

        return $lines === [] ? '' : implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    public static function wslHumanSetupSteps(bool $includeAccountAdd): array
    {
        $steps = [
            'WSL detected — desktop-app CLI integration is not the path we use here.',
        ];
        if ($includeAccountAdd) {
            $steps[] = 'Once per machine:';
            $steps[] = '  op account add';
            $steps[] = '  Sign-in address: ' . self::SIGNIN_ADDRESS;
        }
        $steps[] = 'Each new shell (until session expires):';
        $steps[] = '  eval $(op signin)';
        $steps[] = 'Then: ngramx postmaclone doctor';

        return $steps;
    }

    /**
     * @return list<string>
     */
    public static function nativeHumanSetupSteps(bool $includeAccountAdd): array
    {
        $steps = [];
        if ($includeAccountAdd) {
            $steps[] = 'Add your account once:';
            $steps[] = '  op account add';
            $steps[] = '  Sign-in address: ' . self::SIGNIN_ADDRESS;
        }
        $steps[] = 'Prefer: 1Password desktop app → Developer → Integrate with 1Password CLI';
        $steps[] = 'Fallback for this shell: eval $(op signin)  (never paste your password into ngramx)';

        return $steps;
    }

    /**
     * @return list<string>
     */
    private function listAccountShorthands(): array
    {
        $json = $this->runOp(['account', 'list', '--format=json']);
        if ($json['ok'] && $json['stdout'] !== '') {
            $decoded = json_decode($json['stdout'], true);
            if (is_array($decoded)) {
                $out = [];
                foreach ($decoded as $row) {
                    if (is_array($row) && isset($row['shorthand']) && is_string($row['shorthand']) && $row['shorthand'] !== '') {
                        $out[] = $row['shorthand'];
                    }
                }
                if ($out !== []) {
                    return array_values(array_unique($out));
                }
            }
        }

        $plain = $this->runOp(['account', 'list']);
        if (!$plain['ok'] || $plain['stdout'] === '') {
            return [];
        }

        $out = [];
        foreach (preg_split('/\R/', $plain['stdout']) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, 'SHORTHAND') || str_starts_with($line, '-')) {
                continue;
            }
            $parts = preg_split('/\s+/', $line) ?: [];
            if (isset($parts[0]) && $parts[0] !== '' && !str_contains($parts[0], '://')) {
                $out[] = $parts[0];
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param list<string> $args
     * @return array{ok: bool, stdout: string, error: string}
     */
    private function runOp(array $args): array
    {
        $process = new Process(array_merge(['op'], $args));
        $process->setTimeout(30);
        $process->run();
        $stdout = trim($process->getOutput());
        $stderr = trim($process->getErrorOutput());

        return [
            'ok' => $process->isSuccessful(),
            'stdout' => $stdout,
            'error' => $stderr !== '' ? $stderr : ($process->isSuccessful() ? '' : $stdout),
        ];
    }

    private static function env(string $name): string
    {
        $value = getenv($name);

        return is_string($value) ? $value : '';
    }

    /**
     * @param list<string> $shorthands
     * @param list<array{ok: bool, message: string}> $checks
     * @param list<string> $next
     * @return array{
     *   installed: bool,
     *   service_account: bool,
     *   account_configured: bool,
     *   signed_in: bool,
     *   wsl: bool,
     *   account_shorthands: list<string>,
     *   checks: list<array{ok: bool, message: string}>,
     *   next_steps: list<string>
     * }
     */
    private function result(
        bool $installed,
        bool $serviceAccount,
        bool $accountConfigured,
        bool $signedIn,
        bool $wsl,
        array $shorthands,
        array $checks,
        array $next,
    ): array {
        return [
            'installed' => $installed,
            'service_account' => $serviceAccount,
            'account_configured' => $accountConfigured,
            'signed_in' => $signedIn,
            'wsl' => $wsl,
            'account_shorthands' => $shorthands,
            'checks' => $checks,
            'next_steps' => $next,
        ];
    }
}
