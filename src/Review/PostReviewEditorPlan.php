<?php

declare(strict_types=1);

namespace Ngramx\Review;

use Ngramx\Config\Schema\HookDefinition;

/**
 * What `ngramx review` should launch once the environment is ready.
 */
final readonly class PostReviewEditorPlan
{
    /**
     * @param list<HookDefinition> $hooks
     */
    public function __construct(
        public bool $openCursor = false,
        public array $hooks = [],
    ) {
    }

    public function launchesAnything(): bool
    {
        return $this->openCursor || $this->hooks !== [];
    }
}
