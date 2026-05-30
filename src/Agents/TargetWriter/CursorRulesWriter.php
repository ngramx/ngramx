<?php

declare(strict_types=1);

namespace Ngramx\Agents\TargetWriter;

/**
 * Writes a .cursor/rules/ngramx.mdc file with alwaysApply: true frontmatter.
 */
final class CursorRulesWriter implements TargetWriterInterface
{
    public function write(string $projectRoot, string $markdown): bool
    {
        $dir = $projectRoot . '/.cursor/rules';
        $path = $dir . '/ngramx.mdc';

        $content = $this->buildMdcContent($markdown);

        if (is_file($path)) {
            $existing = file_get_contents($path);
            if ($existing !== false && hash('sha256', $existing) === hash('sha256', $content)) {
                return false;
            }
        }

        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            return false;
        }

        return $this->writeAtomic($path, $content);
    }

    private function buildMdcContent(string $markdown): string
    {
        $frontmatter = <<<'YAML'
---
description: "Ngramx project conventions (architecture, DB, dev environment, ticket workflow)"
alwaysApply: true
---
YAML;

        return $frontmatter . "\n\n" . rtrim($markdown) . "\n";
    }

    private function writeAtomic(string $path, string $content): bool
    {
        $dir = dirname($path);
        $tmp = $dir . '/.ngramx-mdc.' . bin2hex(random_bytes(8)) . '.tmp';

        if (file_put_contents($tmp, $content) === false) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows' && is_file($path)) {
            @unlink($path);
        }

        if (!@rename($tmp, $path)) {
            $ok = @copy($tmp, $path);
            @unlink($tmp);
            return $ok;
        }

        return true;
    }
}
