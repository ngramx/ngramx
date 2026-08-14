<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Backup;

interface BackupSourceInterface
{
    /**
     * Materialise a local dump file path ready for restore.
     */
    public function materialize(): string;

    /**
     * Optional probe for --dry-run (size / existence).
     *
     * @return array{exists: bool, size?: int|null, detail?: string, modified_at?: int|null}
     */
    public function probe(): array;

    /**
     * Unix timestamp of the artifact's origin mtime, or null if unknown.
     *
     * Remote sources must use the object Last-Modified (not the local cache file).
     */
    public function lastModified(): ?int;

    public function cleanup(bool $keep): void;
}
