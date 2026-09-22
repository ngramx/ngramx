<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Restore;

use Ngramx\Postmaclone\Exception\PostmacloneException;
use Ngramx\Postmaclone\Progress\PercentReporter;
use Ngramx\Postmaclone\Target\EphemeralTarget;

class MysqlRestorer implements RestorerInterface
{
    /**
     * Hydra restore exceeded both 1h and 3h Symfony caps. Null means no process
     * timeout; the GitHub job timeout is the remaining limit.
     */
    public const RESTORE_TIMEOUT_SECONDS = null;

    /** @var list<string> */
    public const RESTORE_CLIENT_ARGS = [
        '--compress',
        '--max-allowed-packet=1G',
        '--init-command=SET SESSION foreign_key_checks=0, unique_checks=0',
    ];

    /**
     * @var (callable(string): void)|null
     */
    private $onProgress;

    /**
     * @param (callable(string): void)|null $onProgress
     */
    public function __construct(
        private readonly MysqlRunner $mysql = new MysqlRunner(),
        private readonly MysqlDumpSanitizer $sanitizer = new MysqlDumpSanitizer(),
        ?callable $onProgress = null,
    ) {
        $this->onProgress = $onProgress;
    }

    public function restore(string $dumpPath, EphemeralTarget $target): void
    {
        if (!is_file($dumpPath)) {
            throw new PostmacloneException("Dump not found: {$dumpPath}");
        }

        $in = fopen($dumpPath, 'rb');
        if ($in === false) {
            throw new PostmacloneException("Failed to open dump: {$dumpPath}");
        }

        try {
            $this->appendProgressFilter($in, $dumpPath);
            $this->sanitizer->appendFilter($in);
            $this->progress('Loading dump into scratch (this can take several hours)');
            $this->mysql->run($target, self::RESTORE_CLIENT_ARGS, $in, self::RESTORE_TIMEOUT_SECONDS);
            $this->progress('Restore finished');
        } catch (PostmacloneException $e) {
            throw new PostmacloneException(
                'mysql restore failed: ' . $e->getMessage()
                . "\nTip: ngramx postmaclone doctor",
                0,
                $e
            );
        } finally {
            fclose($in);
        }
    }

    /**
     * @param resource $stream
     */
    private function appendProgressFilter($stream, string $dumpPath): void
    {
        if ($this->onProgress === null) {
            return;
        }

        $size = filesize($dumpPath);
        if ($size === false || $size <= 0) {
            return;
        }

        if (!in_array(StreamByteProgressFilter::FILTER_NAME, stream_get_filters(), true)) {
            stream_filter_register(StreamByteProgressFilter::FILTER_NAME, StreamByteProgressFilter::class);
        }

        $reporter = new PercentReporter($size, 'Loading dump into scratch', $this->onProgress);
        $filter = stream_filter_append(
            $stream,
            StreamByteProgressFilter::FILTER_NAME,
            STREAM_FILTER_READ,
            $reporter
        );
        if ($filter === false) {
            throw new PostmacloneException('Failed to attach MySQL dump progress filter');
        }
    }

    private function progress(string $message): void
    {
        if ($this->onProgress !== null) {
            ($this->onProgress)($message);
        }
    }
}
