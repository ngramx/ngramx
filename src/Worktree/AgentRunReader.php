<?php

declare(strict_types=1);

namespace Ngramx\Worktree;

/**
 * Reads the `.ngramx-agent.json` marker an agent runner leaves in an
 * environment's checkout, alongside `.ngramx.lock`.
 *
 * ## Why a file in the checkout
 *
 * The alternative is a database of runs somewhere central, which then has to be
 * kept in step with what Docker and the filesystem are actually doing. Putting
 * the record inside the worktree makes that drift impossible in the direction
 * that matters: `ngramx worktree --cleanup` deletes the directory, and the run
 * record goes with it. There is no such thing as a row pointing at an
 * environment that no longer exists.
 *
 * It also means the record is readable by anyone with the checkout — the
 * overview shows agent activity with no API call and no credentials.
 *
 * ## Never throws
 *
 * `ngramx status` must survive a truncated, half-written or hand-mangled marker
 * file. Every failure path here returns null, because a missing agent column is
 * a much better outcome than an unusable status command.
 */
class AgentRunReader
{
    public const MARKER_FILENAME = '.ngramx-agent.json';

    /**
     * Read the marker for a checkout, or null when there isn't a usable one.
     *
     * @param string $checkoutPath The worktree or main checkout directory.
     */
    public function read(string $checkoutPath): ?AgentRun
    {
        $path = rtrim($checkoutPath, '/\\') . '/' . self::MARKER_FILENAME;

        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);
        if ($contents === false || trim($contents) === '') {
            return null;
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // A runner writing the file while we read it is the common case
            // here, and it fixes itself on the next invocation.
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        return AgentRun::fromArray($decoded);
    }
}
