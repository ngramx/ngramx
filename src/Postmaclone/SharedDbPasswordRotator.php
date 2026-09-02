<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone;

use Ngramx\Config\Schema\Postmaclone\DbConnectionConfig;
use Ngramx\Config\Schema\Postmaclone\PostmacloneConfig;
use Ngramx\Config\Schema\Postmaclone\SharedDbConfig;
use Ngramx\Postmaclone\Backup\OpSecretWriter;
use Ngramx\Postmaclone\Connection\ConnectionFactory;
use Ngramx\Postmaclone\Connection\DatabaseConnectionUrl;
use Ngramx\Postmaclone\Connection\RemoteDbConnectionResolver;
use Ngramx\Postmaclone\Exception\PostmacloneException;
use Ngramx\Postmaclone\Restore\PsqlRunner;
use Ngramx\Postmaclone\Target\EphemeralTarget;
use Ngramx\Postmaclone\Target\RemoteDbTarget;

/**
 * Rotate shared hosted DB credentials on a schedule and update 1Password.
 */
final class SharedDbPasswordRotator
{
    public const DEFAULT_ROTATION_DAYS = 7;

    public function __construct(
        private readonly OpSecretWriter $opWriter = new OpSecretWriter(),
        private readonly SecurePasswordGenerator $passwords = new SecurePasswordGenerator(),
        private readonly PsqlRunner $psql = new PsqlRunner(),
        private readonly ConnectionFactory $connections = new ConnectionFactory(),
        private readonly RemoteDbConnectionResolver $connectionResolver = new RemoteDbConnectionResolver(),
    ) {
    }

    /**
     * @return array{rotated: bool, rotated_at: string|null, credential_key: string|null}
     */
    public function rotateIfDue(
        string $engine,
        SharedDbConfig $shared,
        ?string $lastRotatedAt = null,
    ): array {
        $credentialKey = $this->credentialKey($shared);
        $days = $shared->passwordRotationDays ?? self::DEFAULT_ROTATION_DAYS;
        if ($days <= 0 || !$shared->isConfigured()) {
            return [
                'rotated' => false,
                'rotated_at' => $lastRotatedAt,
                'credential_key' => $credentialKey,
            ];
        }

        $connection = $shared->connection;
        if ($connection === null) {
            return [
                'rotated' => false,
                'rotated_at' => $lastRotatedAt,
                'credential_key' => $credentialKey,
            ];
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($lastRotatedAt === null) {
            return [
                'rotated' => false,
                'rotated_at' => $now->format('c'),
                'credential_key' => $credentialKey,
            ];
        }

        $last = new \DateTimeImmutable($lastRotatedAt);
        $dueAt = $last->modify('+' . $days . ' days');
        if ($now < $dueAt) {
            return [
                'rotated' => false,
                'rotated_at' => $lastRotatedAt,
                'credential_key' => $credentialKey,
            ];
        }

        $currentUrl = $this->connectionResolver->resolve($connection, $engine);
        $parsed = DatabaseConnectionUrl::parse($currentUrl);
        $newPassword = $this->passwords->generate();
        $target = (new RemoteDbTarget($currentUrl))->provision($engine, 24);
        [$opRef, $newSecret, $oldSecret] = $this->opRotationPayload($connection, $parsed, $currentUrl, $newPassword);

        $this->opWriter->write($opRef, $newSecret);
        try {
            $this->setDatabasePassword($engine, $target, $parsed->username, $newPassword);
        } catch (\Throwable $e) {
            try {
                $this->opWriter->write($opRef, $oldSecret);
            } catch (\Throwable) {
                throw new PostmacloneException(
                    'Password rotation failed in the database and reverting 1Password also failed. '
                    . 'The shared role still uses the previous password. ' . $e->getMessage(),
                    0,
                    $e,
                );
            }

            throw new PostmacloneException(
                'Password rotation failed in the database; 1Password was reverted. ' . $e->getMessage(),
                0,
                $e,
            );
        }

        return [
            'rotated' => true,
            'rotated_at' => $now->format('c'),
            'credential_key' => $credentialKey,
        ];
    }

    public function credentialKey(SharedDbConfig $shared): ?string
    {
        $connection = $shared->connection;
        if ($connection === null) {
            return null;
        }

        if ($connection->usesCredentialParts()) {
            return $connection->credentials?->password;
        }

        if ($connection->url !== null && str_starts_with($connection->url, 'op://')) {
            return $connection->url;
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function opRotationPayload(
        DbConnectionConfig $connection,
        DatabaseConnectionUrl $parsed,
        string $currentUrl,
        string $newPassword,
    ): array {
        if ($connection->usesCredentialParts()) {
            $passwordRef = $connection->credentials?->password;
            if ($passwordRef === null || !str_starts_with($passwordRef, 'op://')) {
                throw new PostmacloneException(
                    'shared.credentials.password must be an op:// reference when password_rotation_days is enabled'
                );
            }

            return [$passwordRef, $newPassword, $parsed->password];
        }

        if ($connection->url !== null && str_starts_with($connection->url, 'op://')) {
            return [
                $connection->url,
                $parsed->withPassword($newPassword)->toUrl(),
                $currentUrl,
            ];
        }

        throw new PostmacloneException(
            'Password rotation requires shared credentials.password or shared.url op:// references'
        );
    }

    private function setDatabasePassword(string $engine, EphemeralTarget $target, string $username, string $password): void
    {
        if ($engine === PostmacloneConfig::ENGINE_POSTGRES) {
            $escapedUser = str_replace('"', '""', $username);
            $escapedPass = str_replace("'", "''", $password);
            $this->psql->runQuery(
                $target,
                "ALTER ROLE \"{$escapedUser}\" PASSWORD '{$escapedPass}'",
                60,
            );

            return;
        }

        if (in_array($engine, [PostmacloneConfig::ENGINE_MYSQL, PostmacloneConfig::ENGINE_MARIADB], true)) {
            $escapedUser = str_replace("'", "''", $username);
            $escapedPass = str_replace("'", "''", $password);
            $pdo = $this->connections->fromParts('mysql', $target->host, $target->port, $target->database, $target->username, $target->password);
            $pdo->exec("ALTER USER '{$escapedUser}'@'%' IDENTIFIED BY '{$escapedPass}'");
            $pdo->exec('FLUSH PRIVILEGES');

            return;
        }

        throw new PostmacloneException("Password rotation is not supported for engine {$engine}");
    }
}
