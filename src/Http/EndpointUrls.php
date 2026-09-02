<?php

declare(strict_types=1);

namespace Ngramx\Http;

use Ngramx\Config\Schema\DockerConfig;
use Ngramx\Worktree\WorktreeIdentity;

/**
 * The set of browser-facing URLs one environment is reachable on: the primary
 * (`docker.app_url`) plus every `docker.endpoints.<name>`, all carrying the
 * host/port that environment actually listens on.
 *
 * Built in two flavours:
 *   - canonical: the URLs as configured, for the main checkout on its default
 *     ports (also the "before" side when rewriting completion deep-links);
 *   - live: with a port offset / port map applied and, for worktrees, the
 *     "<folder>.localhost" family of hostnames swapped in.
 */
final class EndpointUrls
{
    public const PRIMARY = 'primary';

    /**
     * @param array<string,string> $endpoints name => url
     */
    public function __construct(
        public readonly string $primary,
        public readonly array $endpoints = [],
    ) {
    }

    /**
     * The configured URLs on their default ports.
     */
    public static function canonical(DockerConfig $docker): self
    {
        $endpoints = [];
        foreach ($docker->endpoints as $name => $endpoint) {
            $endpoints[$name] = $endpoint->url;
        }

        return new self($docker->appUrl, $endpoints);
    }

    /**
     * The configured URLs with an offset / per-port remap applied to each.
     *
     * @param array<int,int> $portMap
     */
    public static function shifted(DockerConfig $docker, int $portOffset, array $portMap = []): self
    {
        return self::canonical($docker)->map(
            static fn (string $url): string => UrlPortOffset::applyMap(UrlPortOffset::apply($url, $portOffset), $portMap)
        );
    }

    /**
     * Rebuild from a lock file's recorded primary + named URLs, falling back to
     * $fallback for anything the lock does not know (older lock files, or an
     * endpoint added to ngramx.yml after the environment started).
     *
     * @param array<string,string> $urls
     */
    public static function fromRecorded(?string $primary, array $urls, self $fallback): self
    {
        $endpoints = $fallback->endpoints;
        foreach ($urls as $name => $url) {
            if (array_key_exists($name, $endpoints)) {
                $endpoints[$name] = $url;
            }
        }

        return new self($primary !== null && $primary !== '' ? $primary : $fallback->primary, $endpoints);
    }

    /**
     * The worktree hostname for an endpoint: "<folder>.localhost" for the
     * primary, "<name>.<folder>.localhost" for the rest, so each endpoint keeps
     * its own origin (cookies and sessions don't collide) while staying inside
     * the loopback-resolving *.localhost family.
     */
    public static function worktreeHost(string $name, string $folderName): string
    {
        $base = WorktreeIdentity::sanitizeSegment($folderName) . '.localhost';

        return $name === self::PRIMARY ? $base : $name . '.' . $base;
    }

    /**
     * @return array<string,string> name => url, primary first
     */
    public function all(): array
    {
        return [self::PRIMARY => $this->primary] + $this->endpoints;
    }

    public function get(string $name): ?string
    {
        return $this->all()[$name] ?? null;
    }

    public function with(string $name, string $url): self
    {
        if ($name === self::PRIMARY) {
            return new self($url, $this->endpoints);
        }

        return new self($this->primary, [...$this->endpoints, $name => $url]);
    }

    /**
     * @param callable(string $url, string $name): string $fn
     */
    public function map(callable $fn): self
    {
        $endpoints = [];
        foreach ($this->endpoints as $name => $url) {
            $endpoints[$name] = $fn($url, $name);
        }

        return new self($fn($this->primary, self::PRIMARY), $endpoints);
    }

    /**
     * Expand `{url}`, `{scheme}`, `{host}`, `{port}` and `{origin}` for the
     * endpoint named $self, and `{url.<name>}` (etc.) for any endpoint — with
     * `{url.primary}` naming docker.app_url. `{port}` is always numeric (the
     * scheme default when the URL carries none), since it mostly feeds
     * settings like REVERB_PORT that want a number.
     *
     * Unknown placeholders are left untouched so a typo is visible in the
     * seeded file rather than silently blanked.
     */
    public function expand(string $template, string $self = self::PRIMARY): string
    {
        $all = $this->all();

        return (string) preg_replace_callback(
            '/\{(url|scheme|host|port|origin)(?:\.([a-z0-9-]+))?\}/',
            function (array $m) use ($all, $self): string {
                $name = $m[2] ?? $self;
                $url = $all[$name] ?? null;
                if ($url === null) {
                    return $m[0];
                }

                return self::component($url, $m[1]) ?? $m[0];
            },
            $template,
        );
    }

    private static function component(string $url, string $part): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower((string) $parts['scheme']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return match ($part) {
            'url' => $url,
            'scheme' => $scheme,
            'host' => (string) $parts['host'],
            'port' => (string) $port,
            'origin' => $scheme . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''),
            default => null,
        };
    }

    /**
     * Every `env:` entry from the config, expanded against these URLs and
     * grouped by the file it belongs in.
     *
     * @return array<string, array<string,string>> file => [VAR => value]
     */
    public function envFiles(DockerConfig $docker): array
    {
        $files = [];
        foreach ($docker->env as $key => $template) {
            $files['.env'][$key] = $this->expand($template, self::PRIMARY);
        }
        foreach ($docker->endpoints as $name => $endpoint) {
            foreach ($endpoint->env as $key => $template) {
                $files[$endpoint->file][$key] = $this->expand($template, $name);
            }
        }

        return $files;
    }
}
