<?php

declare(strict_types=1);

namespace Ngramx\Templates;

final class TemplateDirectory
{
    public static function resolve(): string
    {
        if (\Phar::running() !== '') {
            return \Phar::running() . '/templates';
        }

        $projectRoot = dirname(__DIR__, 2);

        return $projectRoot . '/templates';
    }

    public static function path(string $relativePath): string
    {
        return self::resolve() . '/' . ltrim($relativePath, '/');
    }
}
