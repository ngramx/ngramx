<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone;

use Ngramx\Config\Schema\Postmaclone\PostmacloneConfig;
use Ngramx\Config\Schema\Postmaclone\SharedDbConfig;
use Ngramx\Postmaclone\Connection\RemoteDbConnectionResolver;
use Ngramx\Postmaclone\Exception\PostmacloneException;
use Ngramx\Postmaclone\Restore\DatabaseWiper;
use Ngramx\Postmaclone\Restore\DumpDecompressor;
use Ngramx\Postmaclone\Restore\MysqlRestorer;
use Ngramx\Postmaclone\Restore\PostgresRestorer;
use Ngramx\Postmaclone\Target\RemoteDbTarget;

/**
 * Factory-side: load a published anonymized artifact into the long-lived shared hosted DB.
 */
final class SharedDbRefresher
{
    public function __construct(
        private readonly DatabaseWiper $wiper = new DatabaseWiper(),
        private readonly DumpDecompressor $decompressor = new DumpDecompressor(),
        private readonly PostgresRestorer $postgres = new PostgresRestorer(),
        private readonly MysqlRestorer $mysql = new MysqlRestorer(),
        private readonly RemoteDbConnectionResolver $connectionResolver = new RemoteDbConnectionResolver(),
    ) {
    }

    public function refresh(string $engine, SharedDbConfig $shared, string $artifactPath): void
    {
        if (!$shared->isConfigured()) {
            throw new PostmacloneException('shared connection is required to refresh the hosted database');
        }
        if (!is_file($artifactPath)) {
            throw new PostmacloneException("Shared refresh artifact not found: {$artifactPath}");
        }

        $url = $this->connectionResolver->resolve($shared->connection, $engine);
        $target = (new RemoteDbTarget($url))->provision($engine, 24 * 365);
        $this->wiper->wipe($engine, $target);

        $restorePath = $this->decompressor->maybeDecompress($artifactPath);
        $createdPlain = $restorePath !== $artifactPath;

        try {
            if ($engine === PostmacloneConfig::ENGINE_POSTGRES) {
                $this->postgres->restore($restorePath, $target);
            } else {
                $this->mysql->restore($restorePath, $target);
            }
        } finally {
            if ($createdPlain && is_file($restorePath)) {
                @unlink($restorePath);
            }
        }
    }
}
