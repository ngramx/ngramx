<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Worktree;

use Ngramx\Worktree\AgentRun;
use PHPUnit\Framework\TestCase;

class AgentRunTest extends TestCase
{
    public function test_it_reads_every_field_from_an_array(): void
    {
        $run = AgentRun::fromArray([
            'source' => 'codabyte',
            'runId' => 'run-abc',
            'sessionId' => 'session-xyz',
            'ticket' => 'cor-301',
            'issue' => 'COR-301',
            'issueUrl' => 'https://linear.app/x/issue/COR-301',
            'startedAt' => '2026-01-01T00:00:00+00:00',
            'endedAt' => '2026-01-01T00:20:00+00:00',
            'outcome' => 'succeeded',
            'prUrl' => 'https://github.com/ngramx/ngramx/pull/1',
        ]);

        $this->assertSame('codabyte', $run->source);
        $this->assertSame('run-abc', $run->runId);
        $this->assertSame('session-xyz', $run->sessionId);
        $this->assertSame('cor-301', $run->ticket);
        $this->assertSame('COR-301', $run->issue);
        $this->assertSame('https://linear.app/x/issue/COR-301', $run->issueUrl);
        $this->assertSame('2026-01-01T00:00:00+00:00', $run->startedAt);
        $this->assertSame('2026-01-01T00:20:00+00:00', $run->endedAt);
        $this->assertSame('succeeded', $run->outcome);
        $this->assertSame('https://github.com/ngramx/ngramx/pull/1', $run->prUrl);
    }

    public function test_an_empty_array_yields_an_all_null_run(): void
    {
        $run = AgentRun::fromArray([]);

        $this->assertNull($run->source);
        $this->assertNull($run->outcome);
        $this->assertSame('started', $run->state());
    }

    public function test_unrecognised_keys_are_ignored(): void
    {
        $run = AgentRun::fromArray(['source' => 'codabyte', 'somethingNew' => 'from a later release']);

        $this->assertSame('codabyte', $run->source);
    }

    /**
     * A field we expect to be a string arriving as a structure is treated as
     * absent — better a missing value than the literal text "Array" in output.
     */
    public function test_non_scalar_values_are_treated_as_absent(): void
    {
        $run = AgentRun::fromArray(['source' => ['nested' => true], 'issue' => null]);

        $this->assertNull($run->source);
        $this->assertNull($run->issue);
    }

    public function test_numeric_values_are_accepted_as_strings(): void
    {
        $run = AgentRun::fromArray(['runId' => 12345]);

        $this->assertSame('12345', $run->runId);
    }

    public function test_empty_strings_are_treated_as_absent(): void
    {
        $run = AgentRun::fromArray(['outcome' => '', 'endedAt' => '']);

        $this->assertNull($run->outcome);
        $this->assertNull($run->endedAt);
        $this->assertSame('started', $run->state());
    }

    /**
     * The central honesty rule: a marker file can say a run began and can say
     * how it ended, but it can never assert that one is alive right now.
     */
    public function test_a_run_with_no_recorded_ending_is_started_not_running(): void
    {
        $run = AgentRun::fromArray(['startedAt' => '2026-01-01T00:00:00+00:00']);

        $this->assertSame('started', $run->state());
        $this->assertNotSame('running', $run->state());
    }

    public function test_the_outcome_wins_over_a_bare_ending(): void
    {
        $run = AgentRun::fromArray([
            'endedAt' => '2026-01-01T00:20:00+00:00',
            'outcome' => 'failed',
        ]);

        $this->assertSame('failed', $run->state());
    }

    public function test_an_ending_without_an_outcome_is_reported_as_ended(): void
    {
        $run = AgentRun::fromArray(['endedAt' => '2026-01-01T00:20:00+00:00']);

        $this->assertSame('ended', $run->state());
    }

    public function test_to_array_round_trips_and_includes_the_derived_state(): void
    {
        $data = [
            'source' => 'codabyte',
            'runId' => 'run-abc',
            'sessionId' => null,
            'ticket' => null,
            'issue' => 'COR-301',
            'issueUrl' => null,
            'startedAt' => '2026-01-01T00:00:00+00:00',
            'endedAt' => null,
            'outcome' => null,
            'prUrl' => null,
        ];

        $this->assertSame(
            $data + ['state' => 'started'],
            AgentRun::fromArray($data)->toArray()
        );
    }
}
