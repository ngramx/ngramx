<?php

declare(strict_types=1);

namespace Ngramx\Worktree;

/**
 * A coding-agent run recorded against an environment by whatever tool started
 * it (Codabyte, CI, a local script). Read from the environment's
 * `.ngramx-agent.json` marker file — see {@see AgentRunReader}.
 *
 * Ngramx does not create these files and does not own the agent's lifecycle.
 * It reports what the runner wrote, which is why every field is nullable: a
 * marker file from a newer or older runner must still be readable.
 *
 * ## Facts only — never liveness
 *
 * A marker file records things that cannot go stale because they never change:
 * who ran, against which ticket, when it started, and — once known — how it
 * ended. It deliberately carries no "running" flag.
 *
 * The reason is that nothing keeps a file honest. A runner killed by a reboot
 * or an OOM never gets to update it, so a stored "running" would stay true
 * forever and the overview would confidently report a process that died days
 * ago. Whether an agent is alive right now is only knowable from the runner
 * that owns the process, so `ngramx status` says `started` — the last thing we
 * actually witnessed — rather than guessing at `running`.
 */
readonly class AgentRun
{
    /**
     * @param ?string $source Tool that started the run, e.g. "codabyte".
     * @param ?string $runId The runner's own identifier for the run.
     * @param ?string $sessionId Agent session id, for resuming a conversation.
     * @param ?string $ticket Ticket slug the environment was created for.
     * @param ?string $issue Human-facing issue identifier, e.g. "COR-301".
     * @param ?string $issueUrl Link back to the issue.
     * @param ?string $startedAt ISO-8601 timestamp.
     * @param ?string $endedAt ISO-8601 timestamp; null when the runner has not
     *        recorded an ending — which means "we never saw it finish", not
     *        "it is still going".
     * @param ?string $outcome How it ended: "succeeded", "failed" or "stopped".
     *        Null until the runner records one.
     * @param ?string $prUrl Pull request the run produced, when there is one.
     */
    public function __construct(
        public ?string $source = null,
        public ?string $runId = null,
        public ?string $sessionId = null,
        public ?string $ticket = null,
        public ?string $issue = null,
        public ?string $issueUrl = null,
        public ?string $startedAt = null,
        public ?string $endedAt = null,
        public ?string $outcome = null,
        public ?string $prUrl = null,
    ) {
    }

    /**
     * Build from decoded JSON, ignoring anything unrecognised.
     *
     * Tolerant by design: a marker file is written by another tool on its own
     * release cycle, so an unexpected shape must degrade to fewer fields rather
     * than break `ngramx status`.
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            source: self::stringOrNull($data['source'] ?? null),
            runId: self::stringOrNull($data['runId'] ?? null),
            sessionId: self::stringOrNull($data['sessionId'] ?? null),
            ticket: self::stringOrNull($data['ticket'] ?? null),
            issue: self::stringOrNull($data['issue'] ?? null),
            issueUrl: self::stringOrNull($data['issueUrl'] ?? null),
            startedAt: self::stringOrNull($data['startedAt'] ?? null),
            endedAt: self::stringOrNull($data['endedAt'] ?? null),
            outcome: self::stringOrNull($data['outcome'] ?? null),
            prUrl: self::stringOrNull($data['prUrl'] ?? null),
        );
    }

    /**
     * The last thing we actually witnessed, as a single word for display.
     *
     * Never claims a run is alive: see the class docblock. "started" means the
     * runner recorded a beginning and no ending, which is as much as a file on
     * disk can honestly tell us.
     */
    public function state(): string
    {
        if ($this->outcome !== null && $this->outcome !== '') {
            return $this->outcome;
        }

        return $this->endedAt !== null && $this->endedAt !== '' ? 'ended' : 'started';
    }

    /**
     * @return array<string, ?string>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'runId' => $this->runId,
            'sessionId' => $this->sessionId,
            'ticket' => $this->ticket,
            'issue' => $this->issue,
            'issueUrl' => $this->issueUrl,
            'startedAt' => $this->startedAt,
            'endedAt' => $this->endedAt,
            'outcome' => $this->outcome,
            'prUrl' => $this->prUrl,
            'state' => $this->state(),
        ];
    }

    /**
     * Scalars only: a nested array or object in a field we expect to be a
     * string is treated as absent rather than coerced into "Array".
     */
    private static function stringOrNull(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value === '' ? null : $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }
}
