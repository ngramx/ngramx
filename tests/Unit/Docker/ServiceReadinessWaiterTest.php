<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Docker;

use Ngramx\Config\Schema\ServiceWaitConfig;
use Ngramx\Docker\ContainerExecutor;
use Ngramx\Docker\DockerCompose;
use Ngramx\Docker\Exception\ServiceNotHealthyException;
use Ngramx\Docker\HealthChecker;
use Ngramx\Docker\ServiceReadinessWaiter;
use Ngramx\Output\OutputFormatter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class ServiceReadinessWaiterTest extends TestCase
{
    /** @var DockerCompose&MockObject */
    private DockerCompose $dockerCompose;

    /** @var HealthChecker&MockObject */
    private HealthChecker $healthChecker;

    /** @var ContainerExecutor&MockObject */
    private ContainerExecutor $containerExecutor;

    private OutputFormatter $formatter;

    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->dockerCompose = $this->createMock(DockerCompose::class);
        $this->healthChecker = $this->createMock(HealthChecker::class);
        $this->containerExecutor = $this->createMock(ContainerExecutor::class);
        $this->output = new BufferedOutput();
        $this->formatter = new OutputFormatter($this->output);
    }

    private function waiter(): ServiceReadinessWaiter
    {
        return new ServiceReadinessWaiter(
            $this->dockerCompose,
            $this->healthChecker,
            $this->formatter,
            $this->containerExecutor,
        );
    }

    public function test_returns_immediately_when_wait_list_is_empty(): void
    {
        $waiter = new ServiceReadinessWaiter(
            $this->dockerCompose,
            $this->healthChecker,
            $this->formatter,
            $this->containerExecutor,
        );

        $this->healthChecker->expects($this->never())->method('getHealthStatus');

        $waiter->waitForAll('docker-compose.yml', []);

        $this->assertTrue(true);
    }

    public function test_waits_until_service_becomes_healthy(): void
    {
        $this->healthChecker->method('getHealthStatus')->willReturn('healthy');
        $this->dockerCompose->method('getLatestLogLines')->willReturn([]);

        $waiter = new ServiceReadinessWaiter(
            $this->dockerCompose,
            $this->healthChecker,
            $this->formatter,
        );

        $waiter->waitForAll('docker-compose.yml', [
            new ServiceWaitConfig(service: 'db', timeout: 5),
        ]);

        $this->assertTrue(true);
    }

    public function test_throws_when_service_never_becomes_healthy(): void
    {
        $this->healthChecker->method('getHealthStatus')->willReturn('starting');
        $this->dockerCompose->method('getLatestLogLines')->willReturn([
            'Line one',
            'Line two',
            'Line three',
        ]);

        $waiter = new ServiceReadinessWaiter(
            $this->dockerCompose,
            $this->healthChecker,
            $this->formatter,
        );

        $this->expectException(ServiceNotHealthyException::class);
        $this->expectExceptionMessageMatches('/did not become ready/');

        $waiter->waitForAll('docker-compose.yml', [
            new ServiceWaitConfig(service: 'db', timeout: 1),
        ]);
    }

    public function test_fetches_multi_line_logs_from_docker_compose(): void
    {
        $this->healthChecker->method('getHealthStatus')
            ->willReturnOnConsecutiveCalls('starting', 'healthy');

        $this->dockerCompose->expects($this->atLeastOnce())
            ->method('getLatestLogLines')
            ->with('docker-compose.yml', 'db', 3, null)
            ->willReturn(['log-1', 'log-2', 'log-3']);

        $waiter = new ServiceReadinessWaiter(
            $this->dockerCompose,
            $this->healthChecker,
            $this->formatter,
        );

        $waiter->waitForAll('docker-compose.yml', [
            new ServiceWaitConfig(service: 'db', timeout: 10),
        ]);

        $this->assertTrue(true);
    }

    public function test_wait_for_all_fails_fast_when_monitored_service_is_restarting(): void
    {
        $this->healthChecker->method('getHealthStatus')
            ->willReturnCallback(function (string $composeFile, string $service): string {
                return match ($service) {
                    'db' => 'healthy',
                    'nginx' => 'restarting',
                    default => 'running',
                };
            });

        $waiter = new ServiceReadinessWaiter(
            $this->dockerCompose,
            $this->healthChecker,
            $this->formatter,
        );

        $this->expectException(ServiceNotHealthyException::class);
        $this->expectExceptionMessageMatches('/nginx \(restarting\)/');

        $waiter->waitForAll(
            'docker-compose.yml',
            [new ServiceWaitConfig(service: 'db', timeout: 5)],
            null,
            1,
            ['nginx'],
        );
    }

    public function test_verify_no_services_failed_passes_when_all_services_running(): void
    {
        $this->healthChecker->method('getHealthStatus')->willReturn('running');

        $waiter = new ServiceReadinessWaiter(
            $this->dockerCompose,
            $this->healthChecker,
            $this->formatter,
        );

        $waiter->verifyNoServicesFailed('docker-compose.yml', ['app', 'nginx']);

        $this->assertTrue(true);
    }

    public function test_verify_no_services_failed_reports_each_failed_service(): void
    {
        $this->healthChecker->method('getHealthStatus')
            ->willReturnMap([
                ['docker-compose.yml', 'app', null, 'restarting'],
                ['docker-compose.yml', 'nginx', null, 'exited'],
                ['docker-compose.yml', 'db', null, 'healthy'],
            ]);

        $waiter = new ServiceReadinessWaiter(
            $this->dockerCompose,
            $this->healthChecker,
            $this->formatter,
        );

        $this->expectException(ServiceNotHealthyException::class);
        $this->expectExceptionMessageMatches('/app \(restarting\).*nginx \(exited\)/');

        $waiter->verifyNoServicesFailed('docker-compose.yml', ['app', 'nginx', 'db']);
    }

    public function test_verify_no_services_failed_includes_namespaced_log_hint(): void
    {
        $this->healthChecker->method('getHealthStatus')->willReturn('restarting');

        $waiter = new ServiceReadinessWaiter(
            $this->dockerCompose,
            $this->healthChecker,
            $this->formatter,
        );

        $this->expectException(ServiceNotHealthyException::class);
        $this->expectExceptionMessageMatches('/docker-compose -f docker-compose\.yml -p my-ns logs app/');

        $waiter->verifyNoServicesFailed('docker-compose.yml', ['app'], 'my-ns');
    }

    public function test_verify_no_services_failed_is_no_op_for_empty_list(): void
    {
        $this->healthChecker->expects($this->never())->method('getHealthStatus');

        $waiter = $this->waiter();

        $waiter->verifyNoServicesFailed('docker-compose.yml', []);

        $this->assertTrue(true);
    }

    public function test_ready_via_docker_healthcheck(): void
    {
        $this->healthChecker->method('hasHealthcheck')->willReturn(true);
        $this->healthChecker->method('getHealthStatus')->willReturn('healthy');
        $this->dockerCompose->method('getLatestLogLines')->willReturn([]);

        // No exec probe should run when the healthcheck satisfies readiness.
        $this->containerExecutor->expects($this->never())->method('succeeds');

        $this->waiter()->waitForAll('docker-compose.yml', [
            new ServiceWaitConfig(service: 'app', timeout: 10, healthcheck: true),
        ]);

        $this->assertTrue(true);
    }

    public function test_ready_via_ready_command_exit_zero(): void
    {
        // No healthcheck path; readiness driven solely by the exec probe.
        $this->healthChecker->method('getContainerState')->willReturn('running');
        $this->healthChecker->method('getHealthStatus')->willReturn('running');
        $this->dockerCompose->method('getLatestLogLines')->willReturn([]);

        $this->containerExecutor->expects($this->atLeastOnce())
            ->method('succeeds')
            ->with('docker-compose.yml', 'app', 'php artisan --version', $this->anything(), null)
            ->willReturnOnConsecutiveCalls(false, true);

        $this->waiter()->waitForAll('docker-compose.yml', [
            new ServiceWaitConfig(service: 'app', timeout: 10, readyCommand: 'php artisan --version'),
        ]);

        $this->assertTrue(true);
    }

    public function test_ready_via_log_regex(): void
    {
        $this->healthChecker->method('getContainerState')->willReturn('running');
        $this->healthChecker->method('getHealthStatus')->willReturn('running');

        // The sentinel line only appears after a couple of polls. getLatestLogLines
        // is consulted both by the probe and the live panel, so drive it off a
        // call counter rather than a fixed consecutive sequence.
        $calls = 0;
        $this->dockerCompose->method('getLatestLogLines')
            ->willReturnCallback(function () use (&$calls): array {
                $calls++;
                return $calls >= 3 ? ['Earl Kendrick is ready!'] : ['booting...'];
            });

        $this->containerExecutor->expects($this->never())->method('succeeds');

        $this->waiter()->waitForAll('docker-compose.yml', [
            new ServiceWaitConfig(service: 'app', timeout: 10, readyLog: 'is ready!'),
        ]);

        $this->assertTrue(true);
    }

    public function test_ready_command_timeout_dumps_logs(): void
    {
        $this->healthChecker->method('getContainerState')->willReturn('running');
        $this->healthChecker->method('getHealthStatus')->willReturn('running');
        $this->containerExecutor->method('succeeds')->willReturn(false);
        $this->dockerCompose->method('getLatestLogLines')->willReturn([
            'migrating...',
            'still migrating',
        ]);

        $this->expectException(ServiceNotHealthyException::class);
        $this->expectExceptionMessageMatches('/did not become ready.*Last 2 log line\(s\)/s');

        $this->waiter()->waitForAll('docker-compose.yml', [
            new ServiceWaitConfig(service: 'app', timeout: 1, readyCommand: 'php artisan migrate:status'),
        ]);
    }

    public function test_wait_for_all_aborts_when_waited_service_exits_during_wait(): void
    {
        // The waited-for container itself crashes mid-wait.
        $this->healthChecker->method('getContainerState')->willReturn('exited');
        $this->healthChecker->method('getRestartCount')->willReturn(0);
        $this->dockerCompose->method('getLatestLogLines')->willReturn([
            'Fatal error: could not connect to database',
        ]);

        $this->expectException(ServiceNotHealthyException::class);
        $this->expectExceptionMessageMatches('/crash-looping \(exited\).*could not connect to database/s');

        $this->waiter()->waitForAll('docker-compose.yml', [
            new ServiceWaitConfig(service: 'app', timeout: 30),
        ]);
    }

    public function test_wait_for_all_aborts_when_restart_count_climbs(): void
    {
        $this->healthChecker->method('getContainerState')->willReturn('running');
        // Baseline 0 at start, then climbs to 3 on the first poll.
        $this->healthChecker->method('getRestartCount')
            ->willReturnOnConsecutiveCalls(0, 3, 3);
        $this->dockerCompose->method('getLatestLogLines')->willReturn(['boom']);

        $this->expectException(ServiceNotHealthyException::class);
        $this->expectExceptionMessageMatches('/restart count climbed/');

        $this->waiter()->waitForAll('docker-compose.yml', [
            new ServiceWaitConfig(service: 'app', timeout: 30),
        ]);
    }

    public function test_wait_for_ready_fails_fast_when_container_missing(): void
    {
        $this->healthChecker->method('getRestartCount')->willReturn(0);
        $this->healthChecker->method('getContainerState')->willReturn('unknown');

        $this->expectException(ServiceNotHealthyException::class);
        $this->expectExceptionMessageMatches('/is not running.*ngramx up/s');

        $this->waiter()->waitForReady(
            'docker-compose.yml',
            new ServiceWaitConfig(service: 'app', timeout: 5),
        );
    }

    public function test_wait_for_ready_returns_when_command_probe_passes(): void
    {
        $this->healthChecker->method('getContainerState')->willReturn('running');
        $this->healthChecker->method('getRestartCount')->willReturn(0);
        $this->containerExecutor->expects($this->once())
            ->method('succeeds')
            ->willReturn(true);

        $this->waiter()->waitForReady(
            'docker-compose.yml',
            new ServiceWaitConfig(service: 'app', timeout: 5, readyCommand: 'php artisan --version'),
        );

        $this->assertTrue(true);
    }
}
