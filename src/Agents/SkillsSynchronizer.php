<?php

declare(strict_types=1);

namespace Ngramx\Agents;

/**
 * Copies skill folders from templates/skills/ to target-specific paths in the project.
 *
 * Generated folders are prefixed with `ngramx-` so hand-written skills in the
 * same directory can stay version-controlled. An older unprefixed copy of a
 * bundled skill is removed.
 *
 * Supported targets:
 *  - "cursor" → .cursor/skills/ngramx-<name>/SKILL.md
 *  - "claude" → .claude/skills/ngramx-<name>/SKILL.md
 */
final class SkillsSynchronizer
{
    /** @var array<string, string> Maps target name to relative directory in project */
    private const TARGET_PATHS = [
        'cursor' => '.cursor/skills',
        'claude' => '.claude/skills',
    ];

    public function __construct(
        private readonly ?string $templatesRoot = null,
    ) {
    }

    /**
     * @param list<string> $skillTargets e.g. ['cursor', 'claude']
     * @return bool True if any files were written or updated
     */
    public function sync(string $projectRoot, array $skillTargets): bool
    {
        $skills = (new SkillCatalog($this->templatesRoot))->discover();
        if ($skills === []) {
            return false;
        }

        $changed = false;

        foreach ($skillTargets as $target) {
            if (!isset(self::TARGET_PATHS[$target])) {
                continue;
            }

            $targetDir = rtrim($projectRoot, '/') . '/' . self::TARGET_PATHS[$target];

            foreach ($skills as $skill) {
                $destDir = $targetDir . '/' . $skill['generatedName'];

                if ($this->syncSkillDirectory($skill['sourceDir'], $destDir, $skill['generatedName'])) {
                    $changed = true;
                }

                if ($skill['folder'] !== $skill['generatedName'] && $this->removeTree($targetDir . '/' . $skill['folder'])) {
                    $changed = true;
                }
            }
        }

        return $changed;
    }

    private function syncSkillDirectory(string $sourceDir, string $destDir, string $generatedName): bool
    {
        $entries = scandir($sourceDir);
        if ($entries === false) {
            return false;
        }

        $changed = false;

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $sourcePath = $sourceDir . '/' . $entry;
            $destPath = $destDir . '/' . $entry;

            if (is_file($sourcePath)) {
                $contents = file_get_contents($sourcePath);
                if ($contents === false) {
                    continue;
                }
                if ($entry === 'SKILL.md') {
                    $contents = $this->withGeneratedName($contents, $generatedName);
                }
                if ($this->syncContents($destPath, $contents)) {
                    $changed = true;
                }
            }
        }

        return $changed;
    }

    private function withGeneratedName(string $content, string $generatedName): string
    {
        if (preg_match('/\A---\R/s', $content) !== 1) {
            return $content;
        }

        $end = strpos($content, "\n---", 3);
        if ($end === false) {
            return $content;
        }

        $front = substr($content, 0, $end);
        $rest = substr($content, $end);
        $updated = preg_replace('/^name:\s*.*/m', 'name: ' . $generatedName, $front, 1);

        return is_string($updated) ? $updated . $rest : $content;
    }

    private function syncContents(string $dest, string $newContent): bool
    {
        if (is_file($dest)) {
            $existing = file_get_contents($dest);
            if ($existing !== false && hash('sha256', $existing) === hash('sha256', $newContent)) {
                return false;
            }
        }

        $dir = dirname($dest);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            return false;
        }

        return file_put_contents($dest, $newContent) !== false;
    }

    private function removeTree(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $items = scandir($dir);
        if ($items === false) {
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                unlink($path);
            }
        }

        return rmdir($dir);
    }
}
