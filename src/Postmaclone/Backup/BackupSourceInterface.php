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
     * Unix timestamp of the artifact's origin mtime, or null if the artifact
     * exists but its age is unknown (for example no Last-Modified header).
     *
     * Remote sources must use the object Last-Modified (not the local cache file).
     * Missing files, failed S3 resolve, non-200 HEAD, and network errors must
     * throw rather than returning null.
     *
     * @throws \Ngramx\Postmaclone\Exception\PostmacloneException
     */
    public function lastModified(): ?int;

    public function cleanup(bool $keep): void;
}
