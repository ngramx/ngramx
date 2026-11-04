<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone;

use Ngramx\Config\Schema\Postmaclone\FactoryDatasetConfig;
use Ngramx\Config\Schema\Postmaclone\PostmacloneConfig;
use Ngramx\Postmaclone\Backup\OpSecretReader;
use Ngramx\Postmaclone\Connection\ConnectionFactory;
use Ngramx\Postmaclone\Connection\RemoteDbConnectionResolver;
use Ngramx\Postmaclone\Exception\PostmacloneException;
use PDOException;

/**
 * Keep the consumer (anon) MySQL user off the idle slot while it holds raw prod.
 *
 * Produce writes with the scratch user (GRANT on both databases). Agents only
 * have the anon password, so anon must be REVOKEd from idle before restore and
 * GRANTed only after that slot is anonymized.
 */
final class SharedSlotPrivilegeFence
{
    public function __construct(
        private readonly ConnectionFactory $connections = new ConnectionFactory(),
        private readonly RemoteDbConnectionResolver $connectionResolver = new RemoteDbConnectionResolver(),
        private readonly OpSecretReader $opReader = new OpSecretReader(),
    ) {
    }

    public function revokeConsumer(string $engine, FactoryDatasetConfig $dataset, string $database): void
    {
        if (!$this->isMysql($engine)) {
            return;
        }

        $this->exec(
            $engine,
            $dataset,
            self::mysqlRevokeSql($database, $this->consumerUsername($dataset)),
            ignoreMissingGrant: true,
        );
    }

    public function grantConsumer(string $engine, FactoryDatasetConfig $dataset, string $database): void
    {
        if (!$this->isMysql($engine)) {
            return;
        }

        $this->exec(
            $engine,
            $dataset,
            self::mysqlGrantSql($database, $this->consumerUsername($dataset)),
            ignoreMissingGrant: false,
        );
    }

    public static function mysqlRevokeSql(string $database, string $username): string
    {
        return 'REVOKE ALL PRIVILEGES ON ' . self::quoteMysqlIdent($database)
            . '.* FROM ' . self::quoteMysqlUser($username);
    }

    public static function mysqlGrantSql(string $database, string $username): string
    {
        return 'GRANT ALL PRIVILEGES ON ' . self::quoteMysqlIdent($database)
            . '.* TO ' . self::quoteMysqlUser($username);
    }

    private function exec(
        string $engine,
        FactoryDatasetConfig $dataset,
        string $sql,
        bool $ignoreMissingGrant,
    ): void {
        $remote = $dataset->target->remote;
        if ($remote === null) {
            throw new PostmacloneException('shared.ping_pong requires target.remote to fence consumer grants');
        }

        $url = $this->connectionResolver->resolve($remote, $engine, $dataset->target->remoteUrl);
        $pdo = $this->connections->fromUrl($url);

        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            if ($ignoreMissingGrant && $this->isMissingGrant($e)) {
                return;
            }

            throw new PostmacloneException(
                'Ping-pong consumer GRANT/REVOKE failed. The scratch user needs '
                . 'GRANT OPTION on both slot databases. Original error: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    private function consumerUsername(FactoryDatasetConfig $dataset): string
    {
        $username = $dataset->shared?->connection?->credentials?->username;
        if ($username === null || $username === '') {
            throw new PostmacloneException(
                'shared.ping_pong requires shared credentials username to fence consumer grants'
            );
        }

        return $this->resolveValue($username);
    }

    private function resolveValue(string $value): string
    {
        if (str_starts_with($value, 'op://')) {
            return $this->opReader->read($value);
        }

        return $value;
    }

    private function isMysql(string $engine): bool
    {
        return in_array($engine, [
            PostmacloneConfig::ENGINE_MYSQL,
            PostmacloneConfig::ENGINE_MARIADB,
        ], true);
    }

    private function isMissingGrant(PDOException $e): bool
    {
        if ((int) $e->getCode() === 1141) {
            return true;
        }

        $info = $e->errorInfo;
        if (is_array($info) && isset($info[1]) && (int) $info[1] === 1141) {
            return true;
        }

        return str_contains(strtolower($e->getMessage()), 'there is no such grant');
    }

    public static function quoteMysqlIdent(string $name): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            throw new PostmacloneException("Unsafe MySQL identifier: {$name}");
        }

        return '`' . $name . '`';
    }

    public static function quoteMysqlUser(string $username): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $username) !== 1) {
            throw new PostmacloneException("Unsafe MySQL username: {$username}");
        }

        return "'{$username}'@'%'";
    }
}
