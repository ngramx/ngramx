<?php

declare(strict_types=1);

namespace Ngramx\Docker;

/**
 * Helpers for parsing Docker Compose short-syntax port mapping strings that may
 * contain environment-variable interpolation, e.g. "${PWA_PORT:-3827}:4173".
 *
 * A naive explode(':') corrupts these mappings because the interpolation block
 * itself contains colons (the ":-" default operator) and braces. Splitting on
 * the inner colon and dropping the closing "}" produces output such as
 * "${PWA_PORT:3827:4173" which Docker Compose rejects with
 * "invalid interpolation format". These helpers split on top-level colons only.
 */
final class PortMapping
{
    /**
     * Split a port mapping on its top-level ":" separators, ignoring any colons
     * that appear inside a "${...}" interpolation block.
     *
     * @return list<string>
     */
    public static function split(string $mapping): array
    {
        $parts = [];
        $current = '';
        $braceDepth = 0;
        $length = strlen($mapping);

        for ($i = 0; $i < $length; $i++) {
            $char = $mapping[$i];

            if ($char === '{') {
                $braceDepth++;
                $current .= $char;
            } elseif ($char === '}') {
                if ($braceDepth > 0) {
                    $braceDepth--;
                }
                $current .= $char;
            } elseif ($char === ':' && $braceDepth === 0) {
                $parts[] = $current;
                $current = '';
            } else {
                $current .= $char;
            }
        }

        $parts[] = $current;

        return $parts;
    }

    /**
     * Whether a segment uses environment-variable interpolation (e.g. "${VAR}").
     */
    public static function isInterpolated(string $segment): bool
    {
        return str_contains($segment, '${');
    }

    /**
     * Resolve the numeric host port for a single mapping segment.
     *
     * Returns the integer for a plain port ("8080") or the numeric default of an
     * interpolation ("${VAR:-3827}" / "${VAR-3827}" => 3827). Returns null when
     * the value cannot be determined statically (e.g. "${VAR}" with no default).
     */
    public static function hostPortNumber(string $segment): ?int
    {
        $segment = trim($segment);

        if (preg_match('/^\d+$/', $segment) === 1) {
            return (int) $segment;
        }

        if (preg_match('/^\$\{[A-Za-z_][A-Za-z0-9_]*:?-(\d+)\}$/', $segment, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Apply an integer offset to a host-port segment.
     *
     * - Plain numeric ports are bumped directly ("80" + 1000 => "1080").
     * - Interpolations with a numeric default have the default bumped while the
     *   variable override is preserved ("${VAR:-3827}" + 1000 => "${VAR:-4827}"),
     *   so isolated instances avoid collisions even when the variable is unset.
     * - Any other expression is returned unchanged because it cannot be offset
     *   safely.
     */
    public static function offsetHostPort(string $segment, int $offset): string
    {
        if (preg_match('/^\d+$/', $segment) === 1) {
            return (string) ((int) $segment + $offset);
        }

        if (preg_match('/^\$\{([A-Za-z_][A-Za-z0-9_]*)(:?-)(\d+)\}$/', $segment, $matches) === 1) {
            return '${' . $matches[1] . $matches[2] . ((int) $matches[3] + $offset) . '}';
        }

        return $segment;
    }

    /**
     * Replace a host-port segment with a specific port number.
     *
     * - Plain numeric ports are replaced directly ("5432" => "5532").
     * - Interpolations with a numeric default keep the variable override but
     *   have the default replaced ("${VAR:-5432}" => "${VAR:-5532}").
     * - Any other expression is returned unchanged because it cannot be
     *   rewritten safely.
     */
    public static function replaceHostPort(string $segment, int $newPort): string
    {
        if (preg_match('/^\d+$/', $segment) === 1) {
            return (string) $newPort;
        }

        if (preg_match('/^\$\{([A-Za-z_][A-Za-z0-9_]*)(:?-)\d+\}$/', $segment, $matches) === 1) {
            return '${' . $matches[1] . $matches[2] . $newPort . '}';
        }

        return $segment;
    }
}
