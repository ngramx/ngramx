<?php

declare(strict_types=1);

namespace Ngramx\Config;

/**
 * Deep-merge associative arrays; list values and scalars are replaced by the override.
 *
 * Project-level hook event lists therefore fully replace the matching user-level list,
 * while sibling event keys from both layers are preserved.
 */
final class ArrayDeepMerger
{
    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    public function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (
                is_array($value)
                && isset($base[$key])
                && is_array($base[$key])
                && $this->isAssociative($value)
                && $this->isAssociative($base[$key])
            ) {
                /** @var array<string, mixed> $nestedBase */
                $nestedBase = $base[$key];
                /** @var array<string, mixed> $nestedOverride */
                $nestedOverride = $value;
                $base[$key] = $this->merge($nestedBase, $nestedOverride);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * @param array<mixed> $value
     */
    private function isAssociative(array $value): bool
    {
        if ($value === []) {
            // Empty arrays are treated as lists so an empty project override
            // can clear a user-level hook list for that event.
            return false;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }
}
