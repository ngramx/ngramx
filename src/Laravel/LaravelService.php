<?php

declare(strict_types=1);

namespace Ngramx\Laravel;

use Ngramx\Docker\ContainerExecutor;

class LaravelService
{
    public function __construct(
        private readonly ContainerExecutor $containerExecutor
    ) {
    }

    /**
     * Check if Laravel artisan file exists in the container
     */
    public function hasArtisan(string $composeFile, string $service, ?string $namespace): bool
    {
        $checkProcess = $this->containerExecutor->exec(
            $composeFile,
            $service,
            '[ -f artisan ] || [ -f /var/www/html/artisan ] || [ -f /app/artisan ]',
            10,
            null,
            $namespace
        );

        return $checkProcess->isSuccessful();
    }

    /**
     * Clear application caches
     */
    public function clearCaches(string $composeFile, string $service, ?string $namespace): bool
    {
        $clearProcess = $this->containerExecutor->exec(
            $composeFile,
            $service,
            'php artisan optimize:clear',
            120,
            null,
            $namespace
        );

        return $clearProcess->isSuccessful();
    }

    /**
     * Reset development database
     */
    public function resetDatabase(string $composeFile, string $service, ?string $namespace): bool
    {
        $migrateProcess = $this->containerExecutor->exec(
            $composeFile,
            $service,
            'php artisan migrate:fresh --seed',
            300,
            null,
            $namespace
        );

        return $migrateProcess->isSuccessful();
    }

    /**
     * Resolve the path to the most recent Laravel log file inside the container.
     * Handles both single-file (laravel.log) and daily log configurations.
     *
     * @return string|null The container path to the log file, or null if not found.
     */
    public function resolveLogPath(string $composeFile, string $service, ?string $namespace): ?string
    {
        // Try the single-file log first, then fall back to the latest daily log.
        // Check common Laravel root paths: workdir, /var/www/html, /app.
        $script = <<<'SH'
for base in . /var/www/html /app; do
    single="$base/storage/logs/laravel.log"
    if [ -f "$single" ]; then
        echo "$single"
        exit 0
    fi
    daily=$(ls -t "$base"/storage/logs/laravel-*.log 2>/dev/null | head -n1)
    if [ -n "$daily" ]; then
        echo "$daily"
        exit 0
    fi
done
exit 1
SH;

        $process = $this->containerExecutor->exec(
            $composeFile,
            $service,
            $script,
            10,
            null,
            $namespace
        );

        if (!$process->isSuccessful()) {
            return null;
        }

        $path = trim($process->getOutput());

        return $path !== '' ? $path : null;
    }
}
