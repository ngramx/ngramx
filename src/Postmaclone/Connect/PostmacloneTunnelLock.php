<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Connect;

use Ngramx\Postmaclone\Exception\PostmacloneException;

/**
 * Standalone SSH tunnel session (no project ngramx.yml / .env binding).
 */
final class PostmacloneTunnelLock
{
    private const STATE_DIR = '.ngramx';

    public function __construct(
        private readonly string $engine,
        private readonly ?string $stateRoot = null,
    ) {
    }

    public function path(): string
    {
        $root = $this->stateRoot ?? $this->defaultStateRoot();
        $safeEngine = preg_replace('/[^a-z0-9_-]/', '', strtolower($this->engine)) ?? $this->engine;

        return rtrim($root, '/') . '/' . self::STATE_DIR . '/postmaclone-tunnel-' . $safeEngine . '.lock';
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    public function read(): ?PostmacloneConnectLockData
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

        return PostmacloneConnectLockData::fromArray($data);
    }

    public function write(PostmacloneConnectLockData $data): void
    {
        $dir = dirname($this->path());
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new PostmacloneException("Failed to create directory: {$dir}");
        }

        $json = json_encode($data->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new PostmacloneException('Failed to encode postmaclone tunnel lock file');
        }

        if (file_put_contents($this->path(), $json) === false) {
            throw new PostmacloneException('Failed to write postmaclone tunnel lock file');
        }

        @chmod($this->path(), 0600);
    }

    public function delete(): void
    {
        if ($this->exists()) {
            unlink($this->path());
        }
    }

    private function defaultStateRoot(): string
    {
        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            return $home;
        }

        return sys_get_temp_dir();
    }
}
