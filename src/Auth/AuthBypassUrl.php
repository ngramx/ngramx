<?php

declare(strict_types=1);

namespace Ngramx\Auth;

/**
 * Turn the live application origin plus a minted path (or a configured
 * template) into the URL a reviewer clicks.
 */
final class AuthBypassUrl
{
    public static function absolute(string $appUrl, string $path): string
    {
        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        $parts = parse_url($appUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return rtrim($appUrl, '/') . '/' . ltrim($path, '/');
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        $prefix = '';
        if (isset($parts['path']) && $parts['path'] !== '/' && $parts['path'] !== '') {
            $prefix = rtrim($parts['path'], '/');
        }

        return $origin . $prefix . '/' . ltrim($path, '/');
    }

    public static function fromTemplate(string $template, string $appUrl, string $email): string
    {
        return strtr($template, [
            '{url}' => rtrim($appUrl, '/'),
            '{email}' => $email,
            '{email_query}' => rawurlencode($email),
        ]);
    }
}
