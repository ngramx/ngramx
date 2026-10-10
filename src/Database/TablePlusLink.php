<?php

declare(strict_types=1);

namespace Ngramx\Database;

/**
 * The TablePlus URL to print, or nothing when this environment has no
 * database TablePlus can open from the host.
 */
final readonly class TablePlusLink
{
    public function __construct(
        public ?string $url = null,
    ) {
    }
}
