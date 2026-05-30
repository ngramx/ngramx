<?php

declare(strict_types=1);

namespace Ngramx\Agents\TargetWriter;

/**
 * Writes a CLAUDE.md file at the project root with a Ngramx-managed section.
 */
final class ClaudeMdWriter implements TargetWriterInterface
{
    private const MARKER_BEGIN = '<!-- NGRAMX_CLAUDE_MANAGED_BEGIN -->';

    private const MARKER_END = '<!-- NGRAMX_CLAUDE_MANAGED_END -->';

    public function write(string $projectRoot, string $markdown): bool
    {
        $path = $projectRoot . '/CLAUDE.md';
        $managedSection = self::MARKER_BEGIN . "\n" . rtrim($markdown) . "\n" . self::MARKER_END;

        $existing = is_file($path) ? file_get_contents($path) : false;
        $existing = $existing === false ? null : $existing;

        if ($existing !== null && $this->hasAnyMarker($existing)) {
            $region = $this->findManagedRegion($existing);
            if ($region === null) {
                return false;
            }

            [$beginPos, $endClose] = $region;
            $oldManaged = substr($existing, $beginPos, $endClose - $beginPos);

            if (hash('sha256', $oldManaged) === hash('sha256', $managedSection)) {
                return false;
            }

            $prefix = substr($existing, 0, $beginPos);
            $suffix = substr($existing, $endClose);

            return $this->writeAtomic($path, $prefix . $managedSection . $suffix);
        }

        if ($existing !== null) {
            $trimmed = rtrim($existing);
            return $this->writeAtomic($path, $trimmed === '' ? $managedSection : $trimmed . "\n\n" . $managedSection);
        }

        return $this->writeAtomic($path, $managedSection);
    }

    private function hasAnyMarker(string $contents): bool
    {
        return str_contains($contents, self::MARKER_BEGIN)
            || str_contains($contents, self::MARKER_END);
    }

    /**
     * @return array{0:int,1:int}|null
     */
    private function findManagedRegion(string $contents): ?array
    {
        $beginPos = strpos($contents, self::MARKER_BEGIN);
        if ($beginPos === false) {
            return null;
        }

        $endPos = strpos($contents, self::MARKER_END, $beginPos + strlen(self::MARKER_BEGIN));
        if ($endPos === false) {
            return null;
        }

        return [$beginPos, $endPos + strlen(self::MARKER_END)];
    }

    private function writeAtomic(string $path, string $content): bool
    {
        $dir = dirname($path);
        $tmp = $dir . '/.claude-md.' . bin2hex(random_bytes(8)) . '.tmp';

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
