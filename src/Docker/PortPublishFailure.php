<?php

declare(strict_types=1);

namespace Ngramx\Docker;

/**
 * Parses Docker Desktop / Compose errors that mean a host port could not be
 * published. The common WSL form is:
 *
 *   ports are not available: exposing port TCP 0.0.0.0:64506 -> 127.0.0.1:0:
 *   /forwards/expose returned unexpected status: 500
 */
final class PortPublishFailure
{
    /**
     * @return list<int>
     */
    public static function parsePorts(string $message): array
    {
        if (
            !str_contains($message, '/forwards/expose')
            && !str_contains($message, 'ports are not available')
            && !str_contains($message, 'address already in use')
            && !str_contains($message, 'Bind for')
        ) {
            return [];
        }

        preg_match_all('/exposing port TCP [^:]+:(\d+)/', $message, $expose);
        preg_match_all('/Bind for [^\s]+?:(\d+)/', $message, $bind);
        preg_match_all('/listen tcp\S* [^\s]+?:(\d+)/', $message, $listen);

        $ports = array_merge($expose[1], $bind[1], $listen[1]);

        return array_values(array_unique(array_map(intval(...), $ports)));
    }
}
