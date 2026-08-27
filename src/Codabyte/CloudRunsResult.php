<?php

declare(strict_types=1);

namespace Ngramx\Codabyte;

/**
 * The outcome of asking Codabyte what it is running for this repository.
 *
 * Three distinct answers, because they mean different things to the person
 * reading `ngramx status`:
 *
 * - **not configured** — no API key on this machine. Say nothing at all; most
 *   people are not using a cloud agent and an empty section would be noise.
 * - **unavailable** — configured, but we could not ask. Worth one dim line:
 *   silence would imply "nothing running there", which we do not know.
 * - **runs** — the answer, possibly an empty list.
 */
readonly class CloudRunsResult
{
    /**
     * @param list<CloudRun> $runs
     */
    private function __construct(
        public bool $configured,
        public array $runs,
        public ?string $error,
    ) {
    }

    public static function notConfigured(): self
    {
        return new self(false, [], null);
    }

    /**
     * @param list<CloudRun> $runs
     */
    public static function of(array $runs): self
    {
        return new self(true, $runs, null);
    }

    public static function unavailable(string $error): self
    {
        return new self(true, [], $error);
    }

    public function failed(): bool
    {
        return $this->error !== null;
    }
}
