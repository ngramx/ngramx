<?php

declare(strict_types=1);

namespace Ngramx\Auth;

/**
 * What the in-container mint script reported.
 *
 * `path` is set only for status `ok`. It is either an absolute http(s) URL
 * or a root-relative path (`/login/magic/...`). `detail` is a short reason
 * code (`user-missing`, `not-local`, `failed QueryException`), never a token.
 */
final readonly class AuthBypassAttempt
{
    /**
     * @param list<string> $paths Every `ok` path. The first is the home login
     *        link; later ones carry `?redirect=` for a completion.json deep link.
     */
    public function __construct(
        public string $status,
        public ?string $path = null,
        public ?string $detail = null,
        public array $paths = [],
    ) {
    }
}
