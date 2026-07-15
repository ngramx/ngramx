<?php

declare(strict_types=1);

namespace Ngramx\Config;

/**
 * Manages the .ngramx.lock file for tracking active instances
 */
class LockFile
{
    private const LOCK_FILE = '.ngramx.lock';

    public function __construct(
        private readonly string $workingDirectory = ''
    ) {
    }

    /**
     * Check if a lock file exists
     */
    public function exists(): bool
    {
        return file_exists($this->getLockFilePath());
    }

    /**
     * Read the lock file data
     *
     * @return LockFileData|null
     */
    public function read(): ?LockFileData
    {
        if (!$this->exists()) {
            return null;
        }

        $content = file_get_contents($this->getLockFilePath());
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if ($data === null) {
            return null;
        }

        // JSON object keys are strings; cast them back to the int host ports.
        $portMap = [];
        if (isset($data['port_map']) && is_array($data['port_map'])) {
            foreach ($data['port_map'] as $from => $to) {
                $portMap[(int) $from] = (int) $to;
            }
        }

        return new LockFileData(
            namespace: $data['namespace'] ?? null,
            portOffset: $data['port_offset'] ?? null,
            startedAt: $data['started_at'] ?? date('c'),
            noHostMapping: $data['no_host_mapping'] ?? false,
            herdStopped: $data['herd_stopped'] ?? false,
            caddyStopped: $data['caddy_stopped'] ?? false,
            portMap: $portMap,
        );
    }

    /**
     * Write lock file data
     */
    public function write(LockFileData $data): void
    {
        $content = json_encode([
            'namespace' => $data->namespace,
            'port_offset' => $data->portOffset,
            'started_at' => $data->startedAt,
            'no_host_mapping' => $data->noHostMapping,
            'herd_stopped' => $data->herdStopped,
            'caddy_stopped' => $data->caddyStopped,
            'port_map' => $data->portMap === [] ? null : $data->portMap,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        file_put_contents($this->getLockFilePath(), $content);
    }

    /**
     * Delete the lock file
     */
    public function delete(): void
    {
        if ($this->exists()) {
            unlink($this->getLockFilePath());
        }
    }

    /**
     * Get the full path to the lock file
     */
    private function getLockFilePath(): string
    {
        $dir = $this->workingDirectory ?: getcwd();
        return $dir . '/' . self::LOCK_FILE;
    }
}
