<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone;

/**
 * Generates passwords safe for Postgres/MySQL ALTER statements and URL encoding.
 */
final class SecurePasswordGenerator
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    public function generate(int $length = 32): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $bytes = random_bytes($length);
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= self::ALPHABET[ord($bytes[$i]) % ($max + 1)];
        }

        return $password;
    }
}
