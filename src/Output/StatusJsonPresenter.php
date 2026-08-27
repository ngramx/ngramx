<?php

declare(strict_types=1);

namespace Ngramx\Output;

use Ngramx\Codabyte\CloudRun;
use Ngramx\Codabyte\CloudRunsResult;
use Ngramx\Worktree\EnvironmentSnapshot;

/**
 * Renders `ngramx status` as JSON for other tools to consume.
 *
 * ## Why this exists
 *
 * Anything driving Ngramx programmatically — Codabyte spinning up cloud dev
 * environments, CI, a dashboard — needs the same picture the human overview
 * shows. Scraping the pretty output is not an option: it is ANSI-coloured,
 * column-padded, and its wording is free to change with any release. A stable
 * JSON shape is the contract instead.
 *
 * ## Compatibility
 *
 * `schema` is the version of this envelope. Consumers should check it and
 * degrade rather than guess. New keys may be added within a schema version;
 * existing keys will not change meaning or disappear without a bump.
 */
class StatusJsonPresenter
{
    /**
     * Current envelope version. Bump only for a breaking change to an existing
     * key — additions do not need one.
     */
    public const SCHEMA_VERSION = 1;

    /**
     * The repository-wide overview: the main checkout plus every worktree.
     *
     * @param list<EnvironmentSnapshot> $worktrees
     * @return array<string, mixed>
     */
    public function overview(
        string $repositoryPath,
        EnvironmentSnapshot $root,
        array $worktrees,
        ?CloudRunsResult $cloud = null
    ): array {
        $payload = [
            'schema' => self::SCHEMA_VERSION,
            'repository' => [
                'name' => basename($repositoryPath),
                'path' => $repositoryPath,
            ],
            'project' => $this->environment($root),
            'worktrees' => array_map(fn (EnvironmentSnapshot $w): array => $this->environment($w), $worktrees),
        ];

        // Present only when a cloud lookup was actually attempted, so a
        // consumer can tell "nothing running there" from "we never asked".
        if ($cloud !== null && $cloud->configured) {
            $payload['cloud'] = [
                'error' => $cloud->error,
                'runs' => array_map(fn (CloudRun $run): array => $run->toArray(), $cloud->runs),
            ];
        }

        return $payload;
    }

    /**
     * Per-service health for a single environment (`status --services --json`).
     *
     * @param list<array{name: string, state: string, health: string}> $services
     * @return array<string, mixed>
     */
    public function services(
        ?string $namespace,
        ?int $portOffset,
        ?string $startedAt,
        bool $running,
        array $services
    ): array {
        return [
            'schema' => self::SCHEMA_VERSION,
            'namespace' => $namespace,
            'portOffset' => $portOffset,
            'startedAt' => $startedAt,
            'running' => $running,
            'services' => $services,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function environment(EnvironmentSnapshot $environment): array
    {
        return [
            'name' => $environment->name,
            'path' => $environment->path,
            'branch' => $environment->branch,
            'running' => $environment->running,
            'url' => $environment->url,
            'namespace' => $environment->namespace,
            'isCurrent' => $environment->isCurrent,
            'portOffset' => $environment->portOffset,
            'agent' => $environment->agent?->toArray(),
        ];
    }

    /**
     * Encode for stdout.
     *
     * Pretty-printed because a human runs this by hand at least as often as a
     * script does, and unescaped slashes because escaped URLs are miserable to
     * read. `JSON_THROW_ON_ERROR` is deliberate: silently emitting `false`
     * would hand the caller something that is not JSON at all.
     *
     * @param array<string, mixed> $payload
     */
    public function encode(array $payload): string
    {
        return json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }
}
