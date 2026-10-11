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

    /**
     * Path, query, and fragment of an app URL, for a magic-link `redirect`.
     * The site root is not a deep link.
     */
    public static function redirectPath(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }

        $path = $parts['path'] ?? '';
        if ($path === '' || $path === '/' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }

        if (isset($parts['query']) && $parts['query'] !== '') {
            $path .= '?' . $parts['query'];
        }
        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $path .= '#' . $parts['fragment'];
        }

        return $path;
    }

    /**
     * @param list<string> $magicPaths
     * @return array<string, string>
     */
    public static function deepLinks(string $appUrl, array $magicPaths): array
    {
        $links = [];
        foreach ($magicPaths as $magicPath) {
            $query = parse_url($magicPath, PHP_URL_QUERY);
            if (!is_string($query) || $query === '') {
                continue;
            }

            parse_str($query, $params);
            $redirect = $params['redirect'] ?? null;
            if (!is_string($redirect) || $redirect === '') {
                continue;
            }

            $links[$redirect] = self::absolute($appUrl, $magicPath);
        }

        return $links;
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
