<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Backup;

use Ngramx\Postmaclone\Exception\PostmacloneException;

class LocalBackupSource implements BackupSourceInterface
{
    public function __construct(
        private readonly string $path,
    ) {
    }

    public function materialize(): string
    {
        if (!is_file($this->path)) {
            throw new PostmacloneException("Dump file not found: {$this->path}");
        }

        return $this->path;
    }

    public function probe(): array
    {
        if (!is_file($this->path)) {
            return ['exists' => false, 'detail' => "Missing file: {$this->path}"];
        }

        $mtime = $this->lastModified();

        return [
            'exists' => true,
            'size' => filesize($this->path) ?: 0,
            'detail' => $this->path,
            'modified_at' => $mtime,
        ];
    }

    public function lastModified(): ?int
    {
        if (!is_file($this->path)) {
            throw new PostmacloneException("Dump file not found: {$this->path}");
        }
        $mtime = filemtime($this->path);

        return $mtime === false ? null : $mtime;
    }

    public function cleanup(bool $keep): void
    {
        // Local sources are never deleted.
    }
}
