<?php

declare(strict_types=1);

namespace Ngramx\Filesystem;

final class AbsolutePath
{
    public static function isAbsolute(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return true;
        }

        return preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }

    /**
     * Join $path onto $root unless $path is already absolute.
     * A leading ./ or .\ on a relative path is stripped.
     */
    public static function resolve(string $root, string $path): string
    {
        if (self::isAbsolute($path)) {
            return $path;
        }

        if (str_starts_with($path, './') || str_starts_with($path, '.\\')) {
            $path = substr($path, 2);
        }

        return rtrim($root, '/\\') . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }
}
