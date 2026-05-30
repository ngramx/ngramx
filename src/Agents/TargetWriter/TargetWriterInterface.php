<?php

declare(strict_types=1);

namespace Cortex\Agents\TargetWriter;

interface TargetWriterInterface
{
    /**
     * Write the managed agent content to this target's location.
     *
     * @return bool True if the target was created or modified
     */
    public function write(string $projectRoot, string $markdown): bool;
}
