<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Docker;

use Ngramx\Config\Schema\ServiceWaitConfig;
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

    private OutputFormatter $formatter;

    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->dockerCompose = $this->createMock(DockerCompose::class);
        $this->healthChecker = $this->createMock(HealthChecker::class);
        $this->output = new BufferedOutput();
        $this->formatter = new OutputFormatter($this->output);
    }

    public function test_returns_immediately_when_wait_list_is_empty(): void
    {
        $waiter = new ServiceReadinessWaiter(
            $this->dockerCompose,
            $this->healthChecker,
            $this->formatter,
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
        $this->expectExceptionMessageMatches('/did not become healthy/');

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

        $waiter = new ServiceReadinessWaiter(
            $this->dockerCompose,
            $this->healthChecker,
            $this->formatter,
        );

        $waiter->verifyNoServicesFailed('docker-compose.yml', []);

        $this->assertTrue(true);
    }
}
