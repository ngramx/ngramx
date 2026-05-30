<?php

declare(strict_types=1);

namespace Ngramx\Agents;

final class AgentsMdSynchronizer
{
    public const MARKER_BEGIN = '<!-- NGRAMX_AGENTS_MANAGED_BEGIN -->';

    public const MARKER_END = '<!-- NGRAMX_AGENTS_MANAGED_END -->';

    public function __construct(
        private readonly AgentsManagedBodyProvider $bodyProvider = new AgentsManagedBodyProvider(),
    ) {
    }

    /**
     * @param string|null $innerMarkdownOverride For tests only; when set, skips loading bundled templates
     *
     * @return bool True if AGENTS.md was created or modified
     */
    public function sync(string $projectRoot, ?string $innerMarkdownOverride = null): bool
    {
        if ($this->shouldSkipForEnv()) {
            return false;
        }

        $projectRoot = rtrim($projectRoot, '/');
        $path = $projectRoot . '/AGENTS.md';

        $innerMarkdown = $innerMarkdownOverride ?? $this->bodyProvider->getMarkdown();
        $managedSection = $this->buildManagedSection($innerMarkdown);

        $existing = is_file($path) ? file_get_contents($path) : false;
        $existing = $existing === false ? null : $existing;

        if ($existing !== null && $this->hasAnyMarker($existing)) {
            $region = $this->findManagedRegion($existing);
            if ($region === null) {
                // Malformed marker state (e.g. END before BEGIN, or BEGIN without a
                // following END). Do not touch the file — repeated runs would
                // otherwise append a new managed block on every command. The user
                // can fix or delete the markers and the next sync will recover.
                return false;
            }

            [$beginPos, $endClose] = $region;
            $prefix = substr($existing, 0, $beginPos);
            $suffix = substr($existing, $endClose);
            $oldManaged = substr($existing, $beginPos, $endClose - $beginPos);

            if (hash('sha256', $oldManaged) === hash('sha256', $managedSection)) {
                return false;
            }

            return $this->writeAtomic($path, $prefix . $managedSection . $suffix);
        }

        if ($existing !== null) {
            $trimmed = rtrim($existing);

            return $this->writeAtomic($path, $trimmed === '' ? $managedSection : $trimmed . "\n\n" . $managedSection);
        }

        $intro = "# Agent instructions\n\n"
            . "Add project-specific notes for AI assistants above the Ngramx-managed section.\n\n";

        return $this->writeAtomic($path, $intro . $managedSection);
    }

    /**
     * Returns true if AGENTS.md at the given project root contains any managed
     * marker but not a well-formed BEGIN...END pair. Useful for surfacing a
     * warning from callers like the sync-agents command.
     */
    public function hasMalformedManagedMarkers(string $projectRoot): bool
    {
        $path = rtrim($projectRoot, '/') . '/AGENTS.md';
        if (!is_file($path)) {
            return false;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return false;
        }

        return $this->hasAnyMarker($contents) && $this->findManagedRegion($contents) === null;
    }

    private function hasAnyMarker(string $contents): bool
    {
        return str_contains($contents, self::MARKER_BEGIN)
            || str_contains($contents, self::MARKER_END);
    }

    /**
     * Locate the first well-formed BEGIN...END managed region.
     *
     * @return array{0:int,1:int}|null [$beginPos, $endClose] where $endClose is
     *                                 the exclusive offset one past MARKER_END,
     *                                 or null if no well-formed region exists.
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

    private function shouldSkipForEnv(): bool
    {
        $v = getenv('NGRAMX_SKIP_AGENTS_SYNC');

        return $v !== false && $v !== '' && !in_array(strtolower(trim($v)), ['0', 'false', 'no'], true);
    }

    private function buildManagedSection(string $innerMarkdown): string
    {
        $header = <<<'MD'
---
### Ngramx-managed agent rules

Ngramx CLI replaces everything between the HTML comment markers below. Add project-specific instructions **above** `NGRAMX_AGENTS_MANAGED_BEGIN`. Do not edit between the markers.

---
MD;

        return self::MARKER_BEGIN . "\n"
            . rtrim($header) . "\n\n"
            . rtrim($innerMarkdown) . "\n"
            . self::MARKER_END;
    }

    private function writeAtomic(string $path, string $content): bool
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            return false;
        }

        $tmp = $directory . '/.agents.' . bin2hex(random_bytes(8)) . '.tmp';

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
