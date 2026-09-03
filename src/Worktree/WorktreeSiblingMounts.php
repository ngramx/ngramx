<?php

declare(strict_types=1);

namespace Ngramx\Worktree;

/**
 * Keeps bind mounts that point OUTSIDE the repository working from a worktree.
 *
 * Projects that live next to their siblings mount them relatively:
 *
 *     volumes:
 *       - ..:/var/www/vagrant/hydra-main          # the repo itself
 *       - ../../hydra-frontend:/var/www/vagrant/hydra-frontend
 *
 * Compose resolves those against the compose file's directory. In the base
 * checkout `../../hydra-frontend` is `/projects/hydra-frontend` — correct. A
 * linked worktree sits three levels deeper (`<repo>/.ngramx/worktrees/<name>`),
 * so the same entry resolves to `<repo>/.ngramx/worktrees/hydra-frontend`,
 * which does not exist. Docker then *creates an empty directory* rather than
 * failing, so the stack starts and the missing files only surface later as
 * confusing application errors (hydra: "Unable to find template
 * SupplierAppBundle::login.html.twig").
 *
 * The fix is to re-resolve any escaping relative source against the base
 * checkout and emit it as an absolute path. Compose merges volumes by mount
 * target, so an override entry reusing the same target replaces the source.
 *
 * Mounts that resolve to the project root or below it are left alone: those
 * genuinely refer to this checkout, and in a worktree they *should* follow the
 * worktree (that is how the app sees the ticket's code).
 */
class WorktreeSiblingMounts
{
    /**
     * Override volume entries for one service.
     *
     * @param mixed  $service        Parsed compose service definition.
     * @param string $composeDir     Absolute dir of the compose file in the worktree.
     * @param string $projectRoot    Absolute worktree root (the checkout being run).
     * @param string $baseComposeDir Absolute dir of the same compose file in the base checkout.
     * @return list<string> Entries of the form "<abs source>:<target>[:<mode>]".
     */
    public function rewrite($service, string $composeDir, string $projectRoot, string $baseComposeDir): array
    {
        if (!is_array($service) || !isset($service['volumes']) || !is_array($service['volumes'])) {
            return [];
        }

        $rewritten = [];

        foreach ($service['volumes'] as $volume) {
            // Long syntax (a map) is left alone: it is rare in the projects this
            // targets, and rewriting it means reproducing the whole mapping.
            if (!is_string($volume)) {
                continue;
            }

            $parsed = $this->parse($volume);
            if ($parsed === null) {
                continue;
            }

            [$source, $target, $mode] = $parsed;

            // Only relative sources are ambiguous. Named volumes ("hydra-files")
            // and absolute paths already mean the same thing from anywhere.
            if (!str_starts_with($source, './') && !str_starts_with($source, '../') && $source !== '..' && $source !== '.') {
                continue;
            }

            $resolvedInWorktree = $this->normalize($composeDir . '/' . $source);
            if ($this->isWithin($resolvedInWorktree, $projectRoot)) {
                continue;
            }

            $entry = $this->normalize($baseComposeDir . '/' . $source) . ':' . $target;
            $rewritten[] = $mode === null ? $entry : $entry . ':' . $mode;
        }

        return $rewritten;
    }

    /**
     * Split "src:target[:mode]" while tolerating Windows drive letters ("C:\x").
     *
     * @return array{0: string, 1: string, 2: string|null}|null
     */
    private function parse(string $volume): ?array
    {
        // A leading drive letter would otherwise be read as the source/target
        // separator. Only relative sources are rewritten, so anything with a
        // drive letter can be skipped outright.
        if (preg_match('/^[A-Za-z]:[\\\\\/]/', $volume) === 1) {
            return null;
        }

        $parts = explode(':', $volume);
        if (count($parts) === 2) {
            return [$parts[0], $parts[1], null];
        }
        if (count($parts) === 3) {
            return [$parts[0], $parts[1], $parts[2]];
        }

        return null;
    }

    /**
     * Collapse "." and ".." lexically. Not realpath(): the target of an
     * escaping mount may not exist yet, which is exactly the bug being fixed.
     */
    private function normalize(string $path): string
    {
        $absolute = str_starts_with($path, '/');
        $out = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $segment;
        }

        return ($absolute ? '/' : '') . implode('/', $out);
    }

    /**
     * True when $path is $root or sits underneath it.
     */
    private function isWithin(string $path, string $root): bool
    {
        $root = rtrim($this->normalize($root), '/');

        return $path === $root || str_starts_with($path, $root . '/');
    }
}
