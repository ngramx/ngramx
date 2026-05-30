<?php

declare(strict_types=1);

namespace Cortex\Agents;

use Cortex\Templates\TemplateDirectory;

/**
 * Copies skill folders from templates/skills/ to target-specific paths in the project.
 *
 * Supported targets:
 *  - "cursor" → .cursor/skills/<name>/SKILL.md
 *  - "claude" → .claude/skills/<name>/SKILL.md
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
        $templatesRoot = $this->templatesRoot ?? TemplateDirectory::resolve();
        $skillsSourceDir = $templatesRoot . '/skills';

        if (!is_dir($skillsSourceDir)) {
            return false;
        }

        $skillNames = $this->discoverSkills($skillsSourceDir);
        if ($skillNames === []) {
            return false;
        }

        $changed = false;

        foreach ($skillTargets as $target) {
            if (!isset(self::TARGET_PATHS[$target])) {
                continue;
            }

            $targetDir = rtrim($projectRoot, '/') . '/' . self::TARGET_PATHS[$target];

            foreach ($skillNames as $skillName) {
                $sourceDir = $skillsSourceDir . '/' . $skillName;
                $destDir = $targetDir . '/' . $skillName;

                if ($this->syncSkillDirectory($sourceDir, $destDir)) {
                    $changed = true;
                }
            }
        }

        return $changed;
    }

    /**
     * @return list<string>
     */
    private function discoverSkills(string $skillsSourceDir): array
    {
        $entries = scandir($skillsSourceDir);
        if ($entries === false) {
            return [];
        }

        $skills = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($skillsSourceDir . '/' . $entry) && is_file($skillsSourceDir . '/' . $entry . '/SKILL.md')) {
                $skills[] = $entry;
            }
        }

        sort($skills);
        return $skills;
    }

    private function syncSkillDirectory(string $sourceDir, string $destDir): bool
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
                if ($this->syncFile($sourcePath, $destPath)) {
                    $changed = true;
                }
            }
        }

        return $changed;
    }

    private function syncFile(string $source, string $dest): bool
    {
        $newContent = file_get_contents($source);
        if ($newContent === false) {
            return false;
        }

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
}
