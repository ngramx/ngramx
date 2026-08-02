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
     * @return array{exists: bool, size?: int|null, detail?: string}
     */
    public function probe(): array;

    public function cleanup(bool $keep): void;
}
