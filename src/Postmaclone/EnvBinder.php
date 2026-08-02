<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone;

use Ngramx\Postmaclone\Exception\PostmacloneException;

class EnvBinder
{
    public function __construct(
        private readonly string $projectRoot,
    ) {
    }

    public function envPath(): string
    {
        return rtrim($this->projectRoot, '/') . '/.env';
    }

    public function backupPath(): string
    {
        return rtrim($this->projectRoot, '/') . '/.ngramx/postmaclone.env.bak';
    }

    /**
     * Backup existing .env and rewrite present DB_* keys for the clone.
     *
     * @return string|null backup path if .env existed
     */
    public function bind(PostmacloneLockData $lock): ?string
    {
        $envPath = $this->envPath();
        if (!is_file($envPath)) {
            return null;
        }

        $original = file_get_contents($envPath);
        if ($original === false) {
            throw new PostmacloneException('Failed to read .env');
        }

        $backupDir = dirname($this->backupPath());
        if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
            throw new PostmacloneException("Failed to create {$backupDir}");
        }

        if (file_put_contents($this->backupPath(), $original) === false) {
            throw new PostmacloneException('Failed to write .env backup');
        }
        @chmod($this->backupPath(), 0600);

        $connection = $lock->engine === 'postgres' ? 'pgsql' : 'mysql';
        $replacements = [
            'DB_CONNECTION' => $connection,
            'DB_HOST' => $lock->host,
            'DB_PORT' => (string) $lock->port,
            'DB_DATABASE' => $lock->database,
            'DB_USERNAME' => $lock->username,
            'DB_PASSWORD' => $lock->password,
            'DATABASE_URL' => $lock->databaseUrl,
        ];

        $updated = $this->replacePresentKeys($original, $replacements);
        if (file_put_contents($envPath, $updated) === false) {
            throw new PostmacloneException('Failed to update .env');
        }

        return $this->backupPath();
    }

    public function restore(?string $backupPath = null): bool
    {
        $backup = $backupPath ?? $this->backupPath();
        if (!is_file($backup)) {
            return false;
        }

        $content = file_get_contents($backup);
        if ($content === false) {
            throw new PostmacloneException('Failed to read .env backup');
        }

        if (file_put_contents($this->envPath(), $content) === false) {
            throw new PostmacloneException('Failed to restore .env');
        }

        unlink($backup);

        return true;
    }

    /**
     * @param array<string, string> $replacements
     */
    private function replacePresentKeys(string $content, array $replacements): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
        $present = [];

        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*#/', $line) === 1 || trim($line) === '') {
                continue;
            }
            if (preg_match('/^\s*([A-Z0-9_]+)\s*=/', $line, $m) !== 1) {
                continue;
            }
            $key = $m[1];
            if (!array_key_exists($key, $replacements)) {
                continue;
            }
            $present[$key] = true;
            $value = $replacements[$key];
            $lines[$i] = $key . '=' . $this->formatValue($value);
        }

        // Only rewrite keys that already existed — do not append new ones.
        unset($present);

        return implode("\n", $lines) . (str_ends_with($content, "\n") ? "\n" : '');
    }

    private function formatValue(string $value): string
    {
        if ($value === '' || preg_match('/[\s#"\'\\\\]/', $value) === 1) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }

        return $value;
    }
}
