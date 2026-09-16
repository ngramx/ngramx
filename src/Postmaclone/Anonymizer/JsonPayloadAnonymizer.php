<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Anonymizer;

use Ngramx\Config\Schema\Postmaclone\ColumnRule;

/**
 * Decode a JSON cell, apply path / recursive key rules, encode it again.
 */
class JsonPayloadAnonymizer
{
    /**
     * @param callable(string, mixed, ColumnRule): mixed $scalar
     */
    public function __construct(
        private readonly mixed $scalar,
    ) {
    }

    public function rewrite(mixed $current, ColumnRule $rule): string
    {
        $decoded = $this->decode($current);
        if ($decoded === null) {
            throw new \InvalidArgumentException('Cell is not valid JSON');
        }

        if ($rule->jsonArray !== null && $rule->jsonArray !== '') {
            $decoded = $this->rewriteJsonArray($decoded, $rule);
        }

        if ($rule->json !== []) {
            $decoded = $rule->jsonRecursive
                ? $this->rewriteRecursive($decoded, $rule)
                : $this->rewritePaths($decoded, $rule);
        }

        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function tryRewrite(mixed $current, ColumnRule $rule): ?string
    {
        try {
            return $this->rewrite($current, $rule);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<mixed>|null
     */
    private function decode(mixed $current): ?array
    {
        if (is_array($current)) {
            return $current;
        }
        if (!is_string($current) || trim($current) === '') {
            return null;
        }

        try {
            $decoded = json_decode($current, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<mixed> $decoded
     * @return array<mixed>
     */
    private function rewriteJsonArray(array $decoded, ColumnRule $rule): array
    {
        if (!array_is_list($decoded)) {
            return $decoded;
        }

        foreach ($decoded as $index => $item) {
            if (is_array($item)) {
                continue;
            }
            $decoded[$index] = ($this->scalar)($rule->jsonArray ?? '', $item, $rule);
        }

        return $decoded;
    }

    /**
     * @param array<mixed> $decoded
     * @return array<mixed>
     */
    private function rewritePaths(array $decoded, ColumnRule $rule): array
    {
        foreach ($rule->json as $path => $faker) {
            $decoded = $this->applyPath($decoded, explode('.', $path), $faker, $rule);
        }

        return $decoded;
    }

    /**
     * @param array<mixed> $node
     * @param list<string> $segments
     * @return array<mixed>
     */
    private function applyPath(array $node, array $segments, string $faker, ColumnRule $rule): array
    {
        if ($segments === []) {
            return $node;
        }

        $head = array_shift($segments);
        if ($head === '*') {
            foreach ($node as $key => $value) {
                $node[$key] = $this->descend($value, $segments, $faker, $rule);
            }

            return $node;
        }

        if (!array_key_exists($head, $node)) {
            return $node;
        }

        $node[$head] = $this->descend($node[$head], $segments, $faker, $rule);

        return $node;
    }

    /**
     * @param list<string> $segments
     */
    private function descend(mixed $value, array $segments, string $faker, ColumnRule $rule): mixed
    {
        if ($segments === []) {
            if (is_array($value)) {
                return $value;
            }

            return ($this->scalar)($faker, $value, $rule);
        }

        return is_array($value) ? $this->applyPath($value, $segments, $faker, $rule) : $value;
    }

    /**
     * @param array<mixed> $node
     * @return array<mixed>
     */
    private function rewriteRecursive(array $node, ColumnRule $rule): array
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->rewriteRecursive($value, $rule);
                continue;
            }
            if (is_string($key) && isset($rule->json[$key])) {
                $node[$key] = ($this->scalar)($rule->json[$key], $value, $rule);
            }
        }

        return $node;
    }
}
