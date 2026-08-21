<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema;

/**
 * Event → hook list mapping after user/project/ngramx.yml sources are merged.
 */
readonly class HooksConfig
{
    /**
     * @param array<string, list<HookDefinition>> $events Keyed by {@see HookEvent} value
     */
    public function __construct(
        public array $events = [],
    ) {
    }

    public function isEmpty(): bool
    {
        foreach ($this->events as $hooks) {
            if ($hooks !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<HookDefinition>
     */
    public function for(HookEvent $event): array
    {
        return $this->events[$event->value] ?? [];
    }
}
