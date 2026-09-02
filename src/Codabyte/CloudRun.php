<?php

declare(strict_types=1);

namespace Ngramx\Codabyte;

/**
 * One environment on the Codabyte server, as reported by `GET /v1/runs`.
 *
 * The remote counterpart of {@see \Ngramx\Worktree\EnvironmentSnapshot}: same
 * question, asked of a machine we are not standing on.
 */
readonly class CloudRun
{
    /**
     * @param string $name Worktree folder name on the server.
     * @param ?string $branch Branch checked out there.
     * @param bool $running Whether its containers are up.
     * @param ?string $url URL on the server's own network — usually not
     *        reachable from here, but it identifies the environment.
     * @param string $agentState What the agent is doing: "running",
     *        "succeeded", "failed", "stopped", "interrupted" or "none".
     *        Unlike the local overview, "running" here is trustworthy — the
     *        server derives it from the live process, not from a file.
     * @param ?string $issue Issue identifier the run is against, e.g. "COR-301".
     * @param ?string $startedAt ISO-8601 timestamp of the run's start.
     */
    public function __construct(
        public string $name,
        public ?string $branch,
        public bool $running,
        public ?string $url,
        public string $agentState,
        public ?string $issue = null,
        public ?string $startedAt = null,
    ) {
    }

    /**
     * @param array<mixed> $data One entry from a repository's `worktrees`.
     */
    public static function fromArray(array $data): self
    {
        $agent = is_array($data['agent'] ?? null) ? $data['agent'] : [];

        return new self(
            name: is_string($data['name'] ?? null) ? $data['name'] : '(unnamed)',
            branch: is_string($data['branch'] ?? null) ? $data['branch'] : null,
            running: (bool) ($data['running'] ?? false),
            url: is_string($data['url'] ?? null) ? $data['url'] : null,
            agentState: is_string($data['agentState'] ?? null) ? $data['agentState'] : 'none',
            issue: is_string($agent['issue'] ?? null) ? $agent['issue'] : null,
            startedAt: is_string($agent['startedAt'] ?? null) ? $agent['startedAt'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'branch' => $this->branch,
            'running' => $this->running,
            'url' => $this->url,
            'agentState' => $this->agentState,
            'issue' => $this->issue,
            'startedAt' => $this->startedAt,
        ];
    }
}
