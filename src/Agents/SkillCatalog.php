<?php

declare(strict_types=1);

namespace Ngramx\Agents;

use Ngramx\Templates\TemplateDirectory;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Discovers bundled skills and the one-line summary used in generated indexes.
 *
 * Generated copies are written under a `ngramx-` prefix so a project's own
 * skills can stay in the same folder and remain version-controlled.
 */
final class SkillCatalog
{
    public const PREFIX = 'ngramx-';

    public function __construct(private readonly ?string $templatesRoot = null)
    {
    }

    public static function generatedName(string $folderName): string
    {
        if (str_starts_with($folderName, self::PREFIX)) {
            return $folderName;
        }

        return self::PREFIX . $folderName;
    }

    /**
     * @return list<array{folder: string, generatedName: string, summary: string, sourceDir: string}>
     */
    public function discover(): array
    {
        $skillsDir = ($this->templatesRoot ?? TemplateDirectory::resolve()) . '/skills';
        if (!is_dir($skillsDir)) {
            return [];
        }

        $entries = scandir($skillsDir);
        if ($entries === false) {
            return [];
        }

        $skills = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $sourceDir = $skillsDir . '/' . $entry;
            $skillFile = $sourceDir . '/SKILL.md';
            if (!is_dir($sourceDir) || !is_file($skillFile)) {
                continue;
            }

            $skills[] = [
                'folder' => $entry,
                'generatedName' => self::generatedName($entry),
                'summary' => $this->summaryFromSkillFile($skillFile, $entry),
                'sourceDir' => $sourceDir,
            ];
        }

        usort($skills, static fn (array $a, array $b): int => $a['folder'] <=> $b['folder']);

        return $skills;
    }

    private function summaryFromSkillFile(string $skillFile, string $folder): string
    {
        $contents = file_get_contents($skillFile);
        if ($contents === false) {
            return $this->fallbackSummary($folder);
        }

        $description = $this->descriptionFromFrontmatter($contents);
        if ($description === '') {
            return $this->fallbackSummary($folder);
        }

        return $this->firstSentence($description);
    }

    private function descriptionFromFrontmatter(string $contents): string
    {
        if (!preg_match('/\A---\R(?<front>.*?)\R---\R/s', $contents, $matches)) {
            return '';
        }

        try {
            $parsed = Yaml::parse($matches['front']);
        } catch (ParseException) {
            return '';
        }

        if (!is_array($parsed)) {
            return '';
        }

        $description = $parsed['description'] ?? '';

        return is_string($description) ? trim($description) : '';
    }

    private function firstSentence(string $description): string
    {
        $flat = trim((string) preg_replace('/\s+/', ' ', $description));
        if ($flat === '') {
            return '';
        }

        $parts = preg_split('/(?<=[.!?])\s+/', $flat, 2);
        $line = is_array($parts) && isset($parts[0]) ? $parts[0] : $flat;

        if (strlen($line) > 180) {
            return substr($line, 0, 177) . '...';
        }

        return $line;
    }

    private function fallbackSummary(string $folder): string
    {
        return 'Follow the ' . self::generatedName($folder) . ' skill when the task matches it.';
    }
}
