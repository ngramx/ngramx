<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Connect;

readonly class PostmacloneConnectLockData
{
    public const MODE_DIRECT = 'direct';
    public const MODE_TUNNEL = 'tunnel';

    public function __construct(
        public string $mode,
        public string $engine,
        public string $connectedAt,
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        public string $password,
        public string $databaseUrl,
        public string $ideHost,
        public int $idePort,
        public ?string $remoteHost = null,
        public ?int $remotePort = null,
        public ?int $localPort = null,
        public ?int $tunnelPid = null,
        public ?string $envBackupPath = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'engine' => $this->engine,
            'connected_at' => $this->connectedAt,
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password,
            'database_url' => $this->databaseUrl,
            'ide_host' => $this->ideHost,
            'ide_port' => $this->idePort,
            'remote_host' => $this->remoteHost,
            'remote_port' => $this->remotePort,
            'local_port' => $this->localPort,
            'tunnel_pid' => $this->tunnelPid,
            'env_backup_path' => $this->envBackupPath,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            mode: (string) ($data['mode'] ?? ''),
            engine: (string) ($data['engine'] ?? ''),
            connectedAt: (string) ($data['connected_at'] ?? ''),
            host: (string) ($data['host'] ?? ''),
            port: (int) ($data['port'] ?? 0),
            database: (string) ($data['database'] ?? ''),
            username: (string) ($data['username'] ?? ''),
            password: (string) ($data['password'] ?? ''),
            databaseUrl: (string) ($data['database_url'] ?? ''),
            ideHost: (string) ($data['ide_host'] ?? '127.0.0.1'),
            idePort: (int) ($data['ide_port'] ?? 0),
            remoteHost: isset($data['remote_host']) ? (string) $data['remote_host'] : null,
            remotePort: isset($data['remote_port']) ? (int) $data['remote_port'] : null,
            localPort: isset($data['local_port']) ? (int) $data['local_port'] : null,
            tunnelPid: isset($data['tunnel_pid']) ? (int) $data['tunnel_pid'] : null,
            envBackupPath: isset($data['env_backup_path']) ? (string) $data['env_backup_path'] : null,
        );
    }
}
