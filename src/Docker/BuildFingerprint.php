<?php

declare(strict_types=1);

namespace Ngramx\Docker;

/**
 * Computes the build fingerprint Ngramx bakes into images it builds, so a later
 * `ngramx up` can tell that a cached image no longer matches the Dockerfile it
 * was built from.
 *
 * The fingerprint is a hash of the Dockerfile bytes. That covers every build
 * input expressed in the Dockerfile itself — crucially the `FROM` line, which
 * is the case that used to slip through: bumping `FROM php:8.3-fpm` to
 * `php:8.4-fpm` leaves a perfectly healthy-looking cached image whose runtime
 * no longer satisfies the project's own constraints, and the failure only
 * surfaces as a wall of downstream dependency errors in a crash-looping
 * container.
 *
 * The `FROM` references are recorded in a second, human-readable label purely
 * so the advisory can name what changed instead of just saying "the Dockerfile
 * differs".
 *
 * Timestamps are deliberately not used. BuildKit normalises image config
 * `Created` stamps — distinct images routinely report an identical value — so
 * comparing an image's creation time against a Dockerfile mtime produces both
 * false positives and false negatives.
 */
class BuildFingerprint
{
    public const LABEL_DOCKERFILE_SHA = 'ngramx.build.dockerfile-sha';
    public const LABEL_FROM = 'ngramx.build.from';

    /**
     * Hash of the Dockerfile's contents, or null when it cannot be read.
     *
     * Line endings are normalised first so a CRLF/LF checkout difference does
     * not read as a changed Dockerfile.
     */
    public function dockerfileSha(string $dockerfilePath): ?string
    {
        $contents = @file_get_contents($dockerfilePath);
        if (!is_string($contents)) {
            return null;
        }

        return hash('sha256', $this->normalize($contents));
    }

    /**
     * The image references named by the Dockerfile's `FROM` lines, in order,
     * with any `AS <stage>` alias stripped. Stage aliases referenced by later
     * stages are kept as written — the value is descriptive, not resolved.
     *
     * @return list<string>
     */
    public function fromReferences(string $dockerfilePath): array
    {
        $contents = @file_get_contents($dockerfilePath);
        if (!is_string($contents)) {
            return [];
        }

        if (preg_match_all('/^\s*FROM\s+(?:--[^\s=]+(?:=\S+)?\s+)*(\S+)/mi', $contents, $matches) === false) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    /**
     * The labels to bake into a service image built from this Dockerfile.
     *
     * @return array<string, string> Empty when the Dockerfile cannot be read,
     *         so an unreadable path simply produces no fingerprint rather than
     *         a bogus one that would report every image as stale.
     */
    public function labelsFor(string $dockerfilePath): array
    {
        $sha = $this->dockerfileSha($dockerfilePath);
        if ($sha === null) {
            return [];
        }

        $labels = [self::LABEL_DOCKERFILE_SHA => $sha];

        $from = $this->fromReferences($dockerfilePath);
        if ($from !== []) {
            $labels[self::LABEL_FROM] = implode(',', $from);
        }

        return $labels;
    }

    /**
     * Resolve the Dockerfile a compose service builds from, honouring the
     * `build.context` / `build.dockerfile` keys and the `build: <context>`
     * short form. Null when the service is not built from source or the
     * resolved path does not exist.
     *
     * Shared by the override generator (which bakes the fingerprint in) and the
     * freshness checker (which reads it back), so the two can never disagree
     * about which file was hashed.
     *
     * @param array<string, mixed> $service
     */
    public function dockerfilePathFor(string $composeFile, array $service): ?string
    {
        if (!isset($service['build'])) {
            return null;
        }

        $build = $service['build'];
        $context = is_array($build) ? ($build['context'] ?? '.') : $build;
        if (!is_string($context)) {
            $context = '.';
        }

        if (!str_starts_with($context, '/')) {
            $context = rtrim(dirname($composeFile), '/') . '/' . ltrim($context, './');
        }

        $dockerfile = 'Dockerfile';
        if (is_array($build) && isset($build['dockerfile']) && is_string($build['dockerfile'])) {
            $dockerfile = $build['dockerfile'];
        }

        $path = rtrim($context, '/') . '/' . ltrim($dockerfile, '/');

        return is_file($path) ? $path : null;
    }

    private function normalize(string $content): string
    {
        return str_replace("\r\n", "\n", $content);
    }
}
