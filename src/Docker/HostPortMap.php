<?php

declare(strict_types=1);

namespace Ngramx\Docker;

/**
 * Helpers for the per-port remap (`port_map`: post-offset host port =>
 * replacement). Keys must stay the port Compose would publish after the global
 * offset, because {@see ComposeOverrideGenerator} and {@see \Ngramx\Http\UrlPortOffset}
 * look up that original, not the current replacement.
 */
final class HostPortMap
{
    /**
     * Record that $failed could not be published.
     *
     * If $failed is already a key, its replacement is updated. If it is already
     * a replacement (Docker 500'd on the remapped port), the original key is
     * re-pointed. Otherwise a new entry is added.
     *
     * @param array<int, int> $portMap
     * @return array<int, int>
     */
    public static function repointFailed(array $portMap, int $failed, int $replacement): array
    {
        if (array_key_exists($failed, $portMap)) {
            $portMap[$failed] = $replacement;

            return $portMap;
        }

        $original = array_search($failed, $portMap, true);
        if (is_int($original)) {
            $portMap[$original] = $replacement;

            return $portMap;
        }

        $portMap[$failed] = $replacement;

        return $portMap;
    }
}
