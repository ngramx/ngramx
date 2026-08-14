<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Backup;

use Ngramx\Config\Schema\Postmaclone\BackupCredentialsConfig;
use Ngramx\Filesystem\HostBinary;
use Ngramx\Postmaclone\Exception\PostmacloneException;

/**
 * Resolves Spaces/S3 credentials from the environment, or from op:// refs in ngramx.yml.
 *
 * Plaintext secrets must not be committed — config may only hold 1Password references.
 */
final class S3Credentials
{
    public const EXAMPLE_KEY_REF = 'op://Tech Team Vault/ngramx-db-backup-read-access/username';

    public const EXAMPLE_SECRET_REF = 'op://Tech Team Vault/ngramx-db-backup-read-access/credential';

    public const OP_INSTALL_URL = 'https://developer.1password.com/docs/cli/get-started/';

    public const OP_SERVICE_ACCOUNTS_URL = 'https://developer.1password.com/docs/service-accounts/';

    /** @var array{0: string, 1: string, 2: string|null}|null */
    private ?array $resolved = null;

    public function __construct(
        private readonly ?BackupCredentialsConfig $configRefs = null,
        private readonly ?OpSecretReader $opReader = null,
    ) {
    }

    /**
     * @return array{0: string, 1: string, 2: string|null}
     */
    public function require(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        // Prefer YAML op:// refs when present so read vs write keys stay distinct
        // even if POSTMACLONE_S3_* is set for another bucket in the same shell.
        if ($this->configRefs !== null) {
            $reader = $this->opReader ?? new OpSecretReader();

            return $this->resolved = [
                $reader->read($this->configRefs->key),
                $reader->read($this->configRefs->secret),
                null,
            ];
        }

        $fromEnv = self::fromEnvironment();
        if ($fromEnv !== null) {
            return $this->resolved = $fromEnv;
        }

        throw new PostmacloneException(self::missingCredentialsMessage(false));
    }

    /**
     * @return array{0: string, 1: string, 2: string|null}
     */
    public static function requireFromEnvironment(): array
    {
        return (new self())->require();
    }

    /**
     * @return array{0: string, 1: string, 2: string|null}|null
     */
    public static function fromEnvironment(): ?array
    {
        $key = self::env('POSTMACLONE_S3_KEY') ?: self::env('AWS_ACCESS_KEY_ID');
        $secret = self::env('POSTMACLONE_S3_SECRET') ?: self::env('AWS_SECRET_ACCESS_KEY');
        $token = self::env('POSTMACLONE_S3_SESSION_TOKEN') ?: self::env('AWS_SESSION_TOKEN');

        if ($key === '' || $secret === '') {
            return null;
        }

        // Env may still hold unresolved op:// refs when someone exports them without op run
        if (str_starts_with($key, 'op://') || str_starts_with($secret, 'op://')) {
            return null;
        }

        return [$key, $secret, $token !== '' ? $token : null];
    }

    public static function hasCredentials(): bool
    {
        return self::fromEnvironment() !== null;
    }

    public static function isOpAvailable(): bool
    {
        return HostBinary::exists('op');
    }

    public static function missingCredentialsMessage(bool $hadConfigRefs = false): string
    {
        $lines = [
            'S3 credentials missing.',
            '',
            'Preferred: set 1Password secret references in ngramx.yml (safe to commit):',
            '  postmaclone:',
            '    backup:',
            '      credentials:',
            '        key: "' . self::EXAMPLE_KEY_REF . '"',
            '        secret: "' . self::EXAMPLE_SECRET_REF . '"',
            '',
        ];

        if (self::isOpAvailable()) {
            $lines[] = 'Then: ngramx postmaclone doctor   # verifies op auth';
            $lines[] = '     ngramx postmaclone          # calls `op read` (needs a session or service account)';
        } else {
            $lines[] = 'Install 1Password CLI: ' . self::OP_INSTALL_URL;
        }

        $lines[] = '';
        $lines[] = 'Auth paths:';
        if (HostEnvironment::isWsl()) {
            $lines[] = '  WSL human: op account add (once), then eval $(op signin) each shell';
        } else {
            $lines[] = '  Human: 1Password app + CLI integration (preferred), or eval $(op signin)';
        }
        $lines[] = '  Agent/CI: OP_SERVICE_ACCOUNT_TOKEN (' . self::OP_SERVICE_ACCOUNTS_URL . ')';
        $lines[] = '  Or skip op: export POSTMACLONE_S3_KEY/SECRET, --from ./dump, or a connection URL';

        if ($hadConfigRefs) {
            $lines[] = '';
            $lines[] = 'Config references were present but could not be resolved — see the error above.';
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<array{ok: bool, message: string}>
     */
    public static function doctorChecks(?BackupCredentialsConfig $configRefs = null): array
    {
        $checks = (new OpAuthProbe())->probe()['checks'];

        if ($configRefs !== null) {
            $checks[] = [
                'ok' => true,
                'message' => 'Credential refs in ngramx.yml: ' . $configRefs->key,
            ];
            $checks[] = [
                'ok' => str_starts_with($configRefs->secret, 'op://'),
                'message' => 'Credential secret ref configured',
            ];
        } else {
            $credsOk = self::hasCredentials();
            $checks[] = [
                'ok' => $credsOk,
                'message' => $credsOk
                    ? 'S3 credentials present in environment (POSTMACLONE_S3_* or AWS_*)'
                    : 'No backup.credentials in ngramx.yml and no S3 env vars — add op:// refs under postmaclone.backup.credentials',
            ];
        }

        return $checks;
    }

    private static function env(string $name): string
    {
        $value = getenv($name);

        return is_string($value) ? $value : '';
    }
}
