<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Connect;

/**
 * Direct DB egress is only available from trusted hosts (e.g. Codabyte droplet).
 */
final class TrustedEgressDetector
{
    public const ENV_TRUSTED = 'NGRAMX_TRUSTED_DB_EGRESS';

    public const ENV_FORCE_TUNNEL = 'NGRAMX_FORCE_TUNNEL';

    public function isDirectMode(): bool
    {
        if ($this->env(self::ENV_FORCE_TUNNEL) === '1') {
            return false;
        }

        if ($this->env(self::ENV_TRUSTED) === '1') {
            return true;
        }

        return $this->env('OP_SERVICE_ACCOUNT_TOKEN') !== '';
    }

    private function env(string $name): string
    {
        $value = getenv($name);

        return is_string($value) ? trim($value) : '';
    }
}
