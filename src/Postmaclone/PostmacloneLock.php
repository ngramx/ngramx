<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone;

use Ngramx\Postmaclone\Exception\PostmacloneException;

class PostmacloneLock
{
    private const RELATIVE_PATH = '.ngramx/postmaclone.lock';

    public function __construct(
        private readonly string $projectRoot,
    ) {
    }

    public function path(): string
    {
        return rtrim($this->projectRoot, '/') . '/' . self::RELATIVE_PATH;
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    public function read(): ?PostmacloneLockData
    {
        if (!$this->exists()) {
            return null;
        }

        $content = file_get_contents($this->path());
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return null;
        }

        return PostmacloneLockData::fromArray($data);
    }

    public function write(PostmacloneLockData $data): void
    {
        $dir = dirname($this->path());
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new PostmacloneException("Failed to create directory: {$dir}");
        }

        $json = json_encode($data->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new PostmacloneException('Failed to encode postmaclone lock file');
        }

        if (file_put_contents($this->path(), $json) === false) {
            throw new PostmacloneException('Failed to write postmaclone lock file');
        }

        @chmod($this->path(), 0600);
    }

    public function delete(): void
    {
        if ($this->exists()) {
            unlink($this->path());
        }
    }
}
