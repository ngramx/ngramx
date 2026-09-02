<?php

declare(strict_types=1);

namespace Ngramx\Config;

/**
 * Keep a handful of KEY=value lines in a project's env file(s) in step with
 * the URLs Ngramx decided for the environment (`docker.env` and
 * `docker.endpoints.*.env`).
 *
 * Only the listed keys are touched; everything else in the file is preserved.
 * A missing file is created from the parent checkout's copy (worktrees) or
 * the neighbouring `.example` file, so a fresh worktree's PWA gets a complete
 * env rather than three lonely variables.
 */
final class EnvFileSeeder
{
    /**
     * @param array<string, array<string,string>> $files project-relative file => [KEY => value]
     * @param string|null $parentDir Checkout to copy missing files from before
     *        falling back to `<file>.example`.
     * @return list<string> project-relative files whose contents changed
     */
    public function seed(string $projectDir, array $files, ?string $parentDir = null): array
    {
        $changed = [];

        foreach ($files as $relative => $vars) {
            if ($vars === []) {
                continue;
            }
            $path = rtrim($projectDir, '/') . '/' . ltrim($relative, '/');

            if (!file_exists($path)) {
                $this->createFrom($path, $parentDir !== null ? rtrim($parentDir, '/') . '/' . ltrim($relative, '/') : null);
            }

            $contents = is_file($path) ? (string) @file_get_contents($path) : '';
            $patched = $contents;
            foreach ($vars as $key => $value) {
                $patched = self::patch($patched, $key, $value);
            }

            if ($patched !== $contents) {
                $dir = dirname($path);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                file_put_contents($path, $patched);
                $changed[] = $relative;
            }
        }

        return $changed;
    }

    private function createFrom(string $path, ?string $parentPath): void
    {
        $candidates = [];
        if ($parentPath !== null) {
            $candidates[] = $parentPath;
        }
        $candidates[] = $path . '.example';
        $candidates[] = dirname($path) . '/.env.example';

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $dir = dirname($path);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                @copy($candidate, $path);

                return;
            }
        }
    }

    /**
     * Replace (or append) a KEY=value line. Quotes the value when it contains
     * whitespace or a `#`, which dotenv parsers would otherwise truncate.
     */
    public static function patch(string $contents, string $key, string $value): string
    {
        $rendered = preg_match('/[\s#]/', $value) === 1 ? '"' . addcslashes($value, '"\\') . '"' : $value;
        $line = $key . '=' . $rendered;
        $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';

        if (preg_match($pattern, $contents) === 1) {
            // Literal replacement: the value may contain `$` or `\`.
            $result = preg_replace_callback($pattern, static fn (): string => $line, $contents, 1);

            return $result ?? $contents;
        }

        $separator = ($contents === '' || str_ends_with($contents, "\n")) ? '' : "\n";

        return $contents . $separator . $line . "\n";
    }
}
