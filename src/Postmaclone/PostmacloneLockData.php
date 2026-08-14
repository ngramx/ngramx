<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone;

readonly class PostmacloneLockData
{
    /**
     * @param array<string, mixed> $providerMeta
     */
    public function __construct(
        public string $provider,
        public string $engine,
        public string $createdAt,
        public string $expiresAt,
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        public string $password,
        public string $databaseUrl,
        public ?string $envBackupPath = null,
        public ?string $label = null,
        public array $providerMeta = [],
        public ?string $downloadPath = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'engine' => $this->engine,
            'created_at' => $this->createdAt,
            'expires_at' => $this->expiresAt,
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password,
            'database_url' => $this->databaseUrl,
            'env_backup_path' => $this->envBackupPath,
            'label' => $this->label,
            'provider_meta' => $this->providerMeta,
            'download_path' => $this->downloadPath,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            provider: (string) ($data['provider'] ?? ''),
            engine: (string) ($data['engine'] ?? ''),
            createdAt: (string) ($data['created_at'] ?? ''),
            expiresAt: (string) ($data['expires_at'] ?? ''),
            host: (string) ($data['host'] ?? ''),
            port: (int) ($data['port'] ?? 0),
            database: (string) ($data['database'] ?? ''),
            username: (string) ($data['username'] ?? ''),
            password: (string) ($data['password'] ?? ''),
            databaseUrl: (string) ($data['database_url'] ?? ''),
            envBackupPath: isset($data['env_backup_path']) ? (string) $data['env_backup_path'] : null,
            label: isset($data['label']) ? (string) $data['label'] : null,
            providerMeta: is_array($data['provider_meta'] ?? null) ? $data['provider_meta'] : [],
            downloadPath: isset($data['download_path']) ? (string) $data['download_path'] : null,
        );
    }
}
