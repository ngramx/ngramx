<?php

declare(strict_types=1);

namespace Ngramx\Config;

use RuntimeException;
use Symfony\Component\Dotenv\Dotenv;

/**
 * Read variable names and values from a project's .env file.
 */
class DotEnvFileReader
{
    /**
     * @return array<string, string>|null Parsed variables, or null when the file does not exist
     */
    public function read(string $path): ?array
    {
        if (!file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Failed to read .env file: {$path}");
        }

        return (new Dotenv())->parse($content);
    }
}
