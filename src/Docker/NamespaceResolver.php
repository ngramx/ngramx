<?php

declare(strict_types=1);

namespace Ngramx\Docker;

/**
 * Resolves container namespace based on directory structure
 */
class NamespaceResolver
{
    private const NAMESPACE_PREFIX = 'ngramx';

    /**
     * Derive namespace from directory path
     *
     * Examples:
     *   /workspace/agent-1/project/ -> ngramx-agent-1-project
     *   /home/user/myapp/ -> ngramx-user-myapp
     */
    public function deriveFromDirectory(?string $directory = null): string
    {
        $path = $directory ?? getcwd();

        if ($path === false) {
            $path = '/';
        }

        // Get path segments
        $segments = array_filter(explode('/', $path));

        // Take last 2 segments
        $segments = array_slice($segments, -2);

        // Sanitize segments (remove special characters)
        $segments = array_map(function ($segment) {
            return preg_replace('/[^a-z0-9-]/', '-', strtolower($segment));
        }, $segments);

        // Build namespace
        return self::NAMESPACE_PREFIX . '-' . implode('-', $segments);
    }

    /**
     * Validate a custom namespace
     *
     * @throws \InvalidArgumentException
     */
    public function validate(string $namespace): void
    {
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $namespace)) {
            throw new \InvalidArgumentException(
                "Invalid namespace '{$namespace}'. Must contain only lowercase letters, numbers, and hyphens."
            );
        }

        if (strlen($namespace) > 63) {
            throw new \InvalidArgumentException(
                "Namespace '{$namespace}' is too long. Maximum 63 characters."
            );
        }
    }
}
