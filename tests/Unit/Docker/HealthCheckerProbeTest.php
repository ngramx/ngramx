<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Docker;

use Ngramx\Docker\HealthChecker;
use PHPUnit\Framework\TestCase;

/**
 * A checker whose every probe fails, in one of the two ways that matter: a
 * command that answered non-zero, or one that never answered at all.
 */
class FailingProbeHealthChecker extends HealthChecker
{
    public function __construct(private readonly bool $failUnanswered)
    {
    }

    protected function runProbe(array $command, bool &$unanswered = false): ?string
    {
        $unanswered = $this->failUnanswered;

        return null;
    }
}

/**
 * A checker that answers from canned output and counts what it asked Docker,
 * so the cost of a poll is observable.
 */
class RecordingHealthChecker extends HealthChecker
{
    public int $composeCalls = 0;

    public int $inspectCalls = 0;

    public function __construct(
        public ?string $psOutput,
        public ?string $inspectOutput,
    ) {
    }

    protected function runProbe(array $command, bool &$unanswered = false): ?string
    {
        $unanswered = false;

        if (($command[0] ?? '') === 'docker-compose') {
            $this->composeCalls++;

            return $this->psOutput;
        }

        $this->inspectCalls++;

        return $this->inspectOutput;
    }
}

/**
 * How the health probes behave when Docker is slow, busy, or lying.
 *
 * These are the rules that stop a stalled daemon from taking an environment
 * down with it. A `ngramx worktree` run died with
 *
 *     ✗ Error: The process "'docker-compose' ... 'ps' '-q' 'mysql'"
 *       exceeded the timeout of 60 seconds.
 *
 * while its containers were reported `running`, `ready` and `running`: a probe
 * with no timeout set (Symfony's default 60s) threw out of the readiness poll
 * loop, and nobody caught it.
 */
class HealthCheckerProbeTest extends TestCase
{
    public function test_a_probe_that_times_out_does_not_throw(): void
    {
        $checker = $this->checkerThatFails(unanswered: true);

        $this->assertSame(
            HealthChecker::STATE_UNAVAILABLE,
            $checker->getContainerState('docker-compose.yml', 'mysql'),
            'a stalled daemon must be a state, not an exception'
        );
    }

    public function test_an_unanswered_probe_is_distinct_from_no_container(): void
    {
        $stalled = $this->checkerThatFails(unanswered: true);
        $answered = $this->checkerThatFails(unanswered: false);

        // "could not tell" — worth asking again.
        $this->assertSame(
            HealthChecker::STATE_UNAVAILABLE,
            $stalled->getHealthStatus('docker-compose.yml', 'mysql')
        );

        // "there is no such container" — a real answer, and retrying will not
        // change it.
        $this->assertSame('unknown', $answered->getHealthStatus('docker-compose.yml', 'mysql'));
    }

    public function test_a_missing_compose_file_still_reports_unknown(): void
    {
        // Not stubbed: `docker-compose -f /nonexistent ps` exits non-zero and
        // that is an answer, so the long-standing behaviour is preserved.
        $checker = new HealthChecker();

        $this->assertSame(
            'unknown',
            $checker->getHealthStatus('/nonexistent/docker-compose.yml', 'nonexistent-service')
        );
    }

    public function test_the_restart_count_is_null_rather_than_zero_when_unreadable(): void
    {
        $checker = $this->checkerThatFails(unanswered: true);

        // Zero would be a lie in both directions: it hides a crash loop, then
        // invents one when the real count arrives.
        $this->assertNull($checker->getRestartCount('docker-compose.yml', 'mysql'));
    }

    public function test_the_container_id_is_resolved_once_and_reused(): void
    {
        $checker = $this->recordingChecker([
            'ps' => "abc123\n",
            'inspect' => "running\n",
        ]);

        $checker->getContainerState('docker-compose.yml', 'mysql');
        $checker->getContainerState('docker-compose.yml', 'mysql');
        $checker->getRestartCount('docker-compose.yml', 'mysql');

        // One Compose invocation, not three: `ps` is the expensive question and
        // its answer does not change while the container lives.
        $this->assertSame(1, $checker->composeCalls, 'container ID lookups should be cached');
        $this->assertSame(3, $checker->inspectCalls, 'container state must stay live');
    }

    public function test_each_service_and_project_is_cached_separately(): void
    {
        $checker = $this->recordingChecker([
            'ps' => "abc123\n",
            'inspect' => "running\n",
        ]);

        $checker->getContainerState('docker-compose.yml', 'mysql');
        $checker->getContainerState('docker-compose.yml', 'redis');
        $checker->getContainerState('docker-compose.yml', 'mysql', 'ngramx-other');

        $this->assertSame(3, $checker->composeCalls);
    }

    public function test_a_container_that_stops_answering_is_looked_up_again(): void
    {
        $checker = $this->recordingChecker([
            'ps' => "abc123\n",
            'inspect' => "running\n",
        ]);

        $checker->getContainerState('docker-compose.yml', 'mysql');

        // The container is recreated: the ID we cached no longer inspects.
        $checker->inspectOutput = null;
        $this->assertSame('unknown', $checker->getContainerState('docker-compose.yml', 'mysql'));

        // ...so the next poll resolves the new one rather than asking about a
        // container that no longer exists.
        $checker->inspectOutput = "running\n";
        $checker->psOutput = "def456\n";
        $this->assertSame('running', $checker->getContainerState('docker-compose.yml', 'mysql'));
        $this->assertSame(2, $checker->composeCalls);
    }

    private function checkerThatFails(bool $unanswered): FailingProbeHealthChecker
    {
        return new FailingProbeHealthChecker($unanswered);
    }

    /** @param array{ps: string, inspect: string} $output */
    private function recordingChecker(array $output): RecordingHealthChecker
    {
        return new RecordingHealthChecker($output['ps'], $output['inspect']);
    }
}
