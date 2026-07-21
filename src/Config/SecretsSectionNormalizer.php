<?php

declare(strict_types=1);

namespace Ngramx\Config;

/**
 * Normalise the `secrets` section of ngramx.yml.
 *
 * Supports the documented `secrets.providers` shape and the common shorthand
 * where `secrets` is a list of provider entries:
 *
 *   secrets:
 *     - provider: .env
 *       required: [FLUX_USERNAME]
 */
final class SecretsSectionNormalizer
{
    /**
     * @param array<string, mixed> $secrets
     *
     * @return array<string, mixed>
     */
    public static function normalize(array $secrets): array
    {
        if (self::isProvidersList($secrets)) {
            return ['providers' => $secrets];
        }

        return $secrets;
    }

    /**
     * @param array<string, mixed> $secrets
     */
    public static function isProvidersList(array $secrets): bool
    {
        if ($secrets === [] || isset($secrets['providers']) || isset($secrets['provider']) || isset($secrets['required'])) {
            return false;
        }

        if (!array_is_list($secrets)) {
            return false;
        }

        foreach ($secrets as $entry) {
            if (!is_array($entry) || !isset($entry['provider']) || !is_string($entry['provider'])) {
                return false;
            }
        }

        return true;
    }
}
