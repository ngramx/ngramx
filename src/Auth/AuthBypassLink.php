<?php

declare(strict_types=1);

namespace Ngramx\Auth;

/**
 * The line `ngramx review` should print after the environment is ready.
 * A link, a warning, or nothing (projects that have no local identity login).
 */
final readonly class AuthBypassLink
{
    /**
     * @param array<string, string> $deepLinks Local path (plus query) => absolute magic URL
     */
    public function __construct(
        public ?string $url = null,
        public ?string $hint = null,
        public ?string $warning = null,
        public array $deepLinks = [],
    ) {
    }
}
