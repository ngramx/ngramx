<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Anonymizer;

use Ngramx\Config\Schema\Postmaclone\ColumnRule;

/**
 * Decode a JSON cell, apply path / recursive key rules, encode it again.
 *
 * Decodes objects as stdClass so empty objects stay `{}` after encode.
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
     * @return array<mixed>|\stdClass|null
     */
    private function decode(mixed $current): array|\stdClass|null
    {
        if (is_array($current) || $current instanceof \stdClass) {
            return $current;
        }
        if (!is_string($current) || trim($current) === '') {
            return null;
        }

        try {
            $decoded = json_decode($current, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return $this->isNode($decoded) ? $decoded : null;
    }

    /**
     * @param array<mixed>|\stdClass $decoded
     * @return array<mixed>|\stdClass
     */
    private function rewriteJsonArray(array|\stdClass $decoded, ColumnRule $rule): array|\stdClass
    {
        if (!is_array($decoded) || !array_is_list($decoded)) {
            return $decoded;
        }

        foreach ($decoded as $index => $item) {
            if ($this->isNode($item)) {
                continue;
            }
            $decoded[$index] = ($this->scalar)($rule->jsonArray ?? '', $item, $rule);
        }

        return $decoded;
    }

    /**
     * @param array<mixed>|\stdClass $decoded
     * @return array<mixed>|\stdClass
     */
    private function rewritePaths(array|\stdClass $decoded, ColumnRule $rule): array|\stdClass
    {
        foreach ($rule->json as $path => $faker) {
            $decoded = $this->applyPath($decoded, explode('.', $path), $faker, $rule);
        }

        return $decoded;
    }

    /**
     * @param array<mixed>|\stdClass $node
     * @param list<string> $segments
     * @return array<mixed>|\stdClass
     */
    private function applyPath(array|\stdClass $node, array $segments, string $faker, ColumnRule $rule): array|\stdClass
    {
        if ($segments === []) {
            return $node;
        }

        $head = array_shift($segments);
        if ($head === '*') {
            if (is_array($node)) {
                foreach ($node as $key => $value) {
                    $node[$key] = $this->descend($value, $segments, $faker, $rule);
                }

                return $node;
            }

            foreach (get_object_vars($node) as $key => $value) {
                $node->{$key} = $this->descend($value, $segments, $faker, $rule);
            }

            return $node;
        }

        if (is_array($node)) {
            if (!array_key_exists($head, $node)) {
                return $node;
            }
            $node[$head] = $this->descend($node[$head], $segments, $faker, $rule);

            return $node;
        }

        if (!property_exists($node, $head)) {
            return $node;
        }
        $node->{$head} = $this->descend($node->{$head}, $segments, $faker, $rule);

        return $node;
    }

    /**
     * @param list<string> $segments
     */
    private function descend(mixed $value, array $segments, string $faker, ColumnRule $rule): mixed
    {
        if ($segments === []) {
            if ($this->isNode($value)) {
                return $value;
            }

            return ($this->scalar)($faker, $value, $rule);
        }

        return $this->isNode($value) ? $this->applyPath($value, $segments, $faker, $rule) : $value;
    }

    /**
     * @param array<mixed>|\stdClass $node
     * @return array<mixed>|\stdClass
     */
    private function rewriteRecursive(array|\stdClass $node, ColumnRule $rule): array|\stdClass
    {
        if (is_array($node)) {
            foreach ($node as $key => $value) {
                if ($this->isNode($value)) {
                    $node[$key] = $this->rewriteRecursive($value, $rule);
                    continue;
                }
                if (is_string($key) && isset($rule->json[$key])) {
                    $node[$key] = ($this->scalar)($rule->json[$key], $value, $rule);
                }
            }

            return $node;
        }

        foreach (get_object_vars($node) as $key => $value) {
            if ($this->isNode($value)) {
                $node->{$key} = $this->rewriteRecursive($value, $rule);
                continue;
            }
            if (isset($rule->json[$key])) {
                $node->{$key} = ($this->scalar)($rule->json[$key], $value, $rule);
            }
        }

        return $node;
    }

    /**
     * @phpstan-assert-if-true array<mixed>|\stdClass $value
     */
    private function isNode(mixed $value): bool
    {
        return is_array($value) || $value instanceof \stdClass;
    }
}
