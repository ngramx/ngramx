<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Orchestrator;

use GuzzleHttp\Psr7\Response;
use Ngramx\Config\Schema\DockerConfig;
use Ngramx\Config\Schema\N8nConfig;
use Ngramx\Config\Schema\NgramxConfig;
use Ngramx\Config\Schema\ServiceWaitConfig;
use Ngramx\Config\Schema\SetupConfig;
use Ngramx\Docker\DockerCompose;
use Ngramx\Docker\HealthChecker;
use Ngramx\Docker\NetworkAttachmentChecker;
use Ngramx\Docker\NetworkAttachmentIssue;
use Ngramx\Executor\HostCommandExecutor;
use Ngramx\Http\AppUrlProbe;
use Ngramx\Orchestrator\SetupOrchestrator;
use Ngramx\Output\OutputFormatter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class SetupOrchestratorTest extends TestCase
{
    /** @var DockerCompose&MockObject */
    private DockerCompose $dockerCompose;

    /** @var HostCommandExecutor&MockObject */
    private HostCommandExecutor $hostExecutor;

    /** @var HealthChecker&MockObject */
    private HealthChecker $healthChecker;
    private OutputFormatter $formatter;
    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->dockerCompose = $this->createMock(DockerCompose::class);
        $this->hostExecutor = $this->createMock(HostCommandExecutor::class);
        $this->healthChecker = $this->createMock(HealthChecker::class);
        $this->output = new BufferedOutput();
        $this->formatter = new OutputFormatter($this->output);
    }

    public function test_setup_detects_first_run_and_shows_message(): void
    {
        $config = $this->createConfig();

        $this->dockerCompose->method('hasExistingImages')->willReturn(false);
        $this->dockerCompose->expects($this->once())->method('up');

        $orchestrator = $this->createOrchestrator();
        $result = $orchestrator->setup($config, skipWait: true);

        $display = $this->output->fetch();
        $this->assertStringContainsString('First run detected', $display);
        $this->assertStringContainsString('Building containers may take a few minutes', $display);
        $this->assertGreaterThanOrEqual(0.0, $result['time']);
    }

    public function test_setup_does_not_show_first_run_message_when_images_exist(): void
    {
        $config = $this->createConfig();

        $this->dockerCompose->method('hasExistingImages')->willReturn(true);
        $this->dockerCompose->expects($this->once())->method('up');

        $orchestrator = $this->createOrchestrator();
        $orchestrator->setup($config, skipWait: true);

        $display = $this->output->fetch();
        $this->assertStringNotContainsString('First run detected', $display);
    }

    public function test_setup_skips_wait_when_no_wait_flag_is_set(): void
    {
        $config = $this->createConfig(waitFor: [
            new ServiceWaitConfig(service: 'db', timeout: 30),
        ]);

        $this->dockerCompose->method('hasExistingImages')->willReturn(true);
        $this->dockerCompose->expects($this->once())->method('up');

        $this->healthChecker->expects($this->never())->method('getHealthStatus');

        $orchestrator = $this->createOrchestrator();
        $orchestrator->setup($config, skipWait: true);

        $this->assertTrue(true);
    }

    public function test_setup_waits_for_services_when_configured(): void
    {
        $config = $this->createConfig(waitFor: [
            new ServiceWaitConfig(service: 'db', timeout: 30),
        ]);

        $this->dockerCompose->method('hasExistingImages')->willReturn(true);
        $this->dockerCompose->expects($this->once())->method('up');
        $this->dockerCompose->method('getLatestLogLine')->willReturn(null);

        // Return healthy immediately
        $this->healthChecker->method('getHealthStatus')->willReturn('healthy');

        $orchestrator = $this->createOrchestrator();
        $result = $orchestrator->setup($config);

        $display = $this->output->fetch();
        $this->assertStringContainsString('Waiting for services', $display);
        $this->assertGreaterThanOrEqual(0.0, $result['time']);
    }

    public function test_setup_returns_correct_result_structure(): void
    {
        $config = $this->createConfig();

        $this->dockerCompose->method('hasExistingImages')->willReturn(true);
        $this->dockerCompose->expects($this->once())->method('up');

        $orchestrator = $this->createOrchestrator();
        $result = $orchestrator->setup($config, skipWait: true, namespace: 'test-ns', portOffset: 100);

        $this->assertArrayHasKey('time', $result);
        $this->assertArrayHasKey('namespace', $result);
        $this->assertArrayHasKey('port_offset', $result);
        $this->assertArrayHasKey('app_url_probe', $result);
        $this->assertSame('test-ns', $result['namespace']);
        $this->assertSame(100, $result['port_offset']);
    }

    public function test_setup_throws_when_app_url_probe_returns_502(): void
    {
        $config = $this->createConfig();

        $this->dockerCompose->method('hasExistingImages')->willReturn(true);
        $this->dockerCompose->expects($this->once())->method('up');
        $this->dockerCompose->method('listServices')->willReturn(['app', 'nginx']);
        $this->dockerCompose->method('getLatestLogLines')->willReturn([
            '2026/05/15 11:50:58 [error] connect() failed (111: Connection refused)',
        ]);

        $probe = new AppUrlProbe(static fn () => new Response(502));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/HTTP 502/');

        $orchestrator = $this->createOrchestrator(appUrlProbe: $probe);
        $orchestrator->setup($config, skipWait: true);
    }

    public function test_setup_does_not_probe_when_verify_app_url_is_false(): void
    {
        $config = $this->createConfig();

        $this->dockerCompose->method('hasExistingImages')->willReturn(true);
        $this->dockerCompose->expects($this->once())->method('up');

        $probe = $this->createMock(AppUrlProbe::class);
        $probe->expects($this->never())->method('probe');

        $orchestrator = $this->createOrchestrator(appUrlProbe: $probe);
        $result = $orchestrator->setup($config, skipWait: true, verifyAppUrl: false);

        $this->assertNull($result['app_url_probe']);
    }

    public function test_setup_applies_port_offset_to_probe_url(): void
    {
        $config = $this->createConfig();

        $this->dockerCompose->method('hasExistingImages')->willReturn(true);
        $this->dockerCompose->expects($this->once())->method('up');

        $probedUrls = [];
        $probe = new AppUrlProbe(static function (string $method, string $url) use (&$probedUrls): Response {
            $probedUrls[] = $url;
            return new Response(200);
        });

        $orchestrator = $this->createOrchestrator(appUrlProbe: $probe);
        $orchestrator->setup($config, skipWait: true, portOffset: 8100);

        $this->assertNotEmpty($probedUrls, 'Probe should fire at least once with offset applied.');
        $this->assertStringContainsString(':8180', $probedUrls[0], 'http://localhost:80 + offset 8100 = :8180');
    }

    public function test_setup_returns_probe_result_when_app_url_is_healthy(): void
    {
        $config = $this->createConfig();

        $this->dockerCompose->method('hasExistingImages')->willReturn(true);
        $this->dockerCompose->expects($this->once())->method('up');

        $probe = new AppUrlProbe(static fn () => new Response(302, ['Location' => '/login']));

        $orchestrator = $this->createOrchestrator(appUrlProbe: $probe);
        $result = $orchestrator->setup($config, skipWait: true);

        $this->assertNotNull($result['app_url_probe']);
        $this->assertSame(302, $result['app_url_probe']->statusCode);
    }

    public function test_setup_auto_recovers_a_network_detached_container(): void
    {
        $config = $this->createConfig();

        $this->dockerCompose->method('hasExistingImages')->willReturn(true);
        $this->dockerCompose->expects($this->once())->method('up');

        $checker = $this->createMock(NetworkAttachmentChecker::class);
        $issue = new NetworkAttachmentIssue('db', 'abc123abc123', 'verafind_net');
        $checker->expects($this->once())
            ->method('checkAll')
            ->willReturn([$issue]);
        $checker->expects($this->once())
            ->method('checkService')
            ->with($this->anything(), 'db', $this->anything())
            ->willReturn(null); // fixed after recreate

        $this->dockerCompose->expects($this->once())
            ->method('recreateService')
            ->with('docker-compose.yml', 'db', null);

        $orchestrator = $this->createOrchestrator(checker: $checker);
        $result = $orchestrator->setup($config, skipWait: true);

        $display = $this->output->fetch();
        $this->assertStringContainsString('Reconciling container networks', $display);
        $this->assertStringContainsString('Recreating `db`', $display);
        $this->assertStringContainsString('reattached', $display);
        $this->assertNotNull($result['app_url_probe']);
    }

    public function test_setup_throws_when_network_recreate_does_not_fix_the_desync(): void
    {
        $config = $this->createConfig();

        $this->dockerCompose->method('hasExistingImages')->willReturn(true);
        $this->dockerCompose->expects($this->once())->method('up');

        $checker = $this->createMock(NetworkAttachmentChecker::class);
        $issue = new NetworkAttachmentIssue('db', 'abc123abc123', 'verafind_net');
        $checker->method('checkAll')->willReturn([$issue]);
        // checkService still reports the issue after the recreate attempt.
        $checker->method('checkService')->willReturn($issue);

        $this->dockerCompose->expects($this->once())->method('recreateService');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/still running with no networks attached/');

        $this->createOrchestrator(checker: $checker)->setup($config, skipWait: true);
    }

    public function test_setup_propagates_recreate_failures_with_original_issue_context(): void
    {
        $config = $this->createConfig();

        $this->dockerCompose->method('hasExistingImages')->willReturn(true);
        $this->dockerCompose->expects($this->once())->method('up');

        $checker = $this->createMock(NetworkAttachmentChecker::class);
        $issue = new NetworkAttachmentIssue('db', 'abc123abc123', 'verafind_net');
        $checker->method('checkAll')->willReturn([$issue]);

        $this->dockerCompose->expects($this->once())
            ->method('recreateService')
            ->willThrowException(new \RuntimeException('Failed to remove stale `db`: docker daemon dead'));

        try {
            $this->createOrchestrator(checker: $checker)->setup($config, skipWait: true);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Service `db` is running but has no networks', $e->getMessage());
            $this->assertStringContainsString('Automatic recovery failed', $e->getMessage());
            $this->assertStringContainsString('docker daemon dead', $e->getMessage());
        }
    }

    public function test_setup_surfaces_nginx_log_hint_when_probe_fails(): void
    {
        $config = $this->createConfig();

        $this->dockerCompose->method('hasExistingImages')->willReturn(true);
        $this->dockerCompose->expects($this->once())->method('up');
        $this->dockerCompose->method('getLatestLogLines')->willReturnCallback(
            static function (string $compose, string $service): array {
                return $service === 'nginx'
                    ? ['[error] connect() failed (111: Connection refused) while connecting to upstream']
                    : [];
            }
        );

        $probe = new AppUrlProbe(static fn () => new Response(502));

        try {
            $this->createOrchestrator(appUrlProbe: $probe)->setup($config, skipWait: true);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Last log lines from `nginx`', $e->getMessage());
            $this->assertStringContainsString('Connection refused', $e->getMessage());
        }
    }

    public function test_wait_for_services_polls_all_services(): void
    {
        $config = $this->createConfig(waitFor: [
            new ServiceWaitConfig(service: 'db', timeout: 30),
            new ServiceWaitConfig(service: 'redis', timeout: 30),
        ]);

        $this->dockerCompose->method('hasExistingImages')->willReturn(true);
        $this->dockerCompose->expects($this->once())->method('up');
        $this->dockerCompose->method('getLatestLogLine')->willReturn(null);

        // Both services healthy immediately
        $this->healthChecker->method('getHealthStatus')
            ->willReturnMap([
                ['docker-compose.yml', 'db', null, 'healthy'],
                ['docker-compose.yml', 'redis', null, 'healthy'],
            ]);

        $orchestrator = $this->createOrchestrator();
        $orchestrator->setup($config);

        $display = $this->output->fetch();
        $this->assertStringContainsString('Waiting for services', $display);
    }

    public function test_wait_for_services_throws_on_timeout(): void
    {
        $config = $this->createConfig(waitFor: [
            new ServiceWaitConfig(service: 'db', timeout: 1),
        ]);

        $this->dockerCompose->method('hasExistingImages')->willReturn(true);
        $this->dockerCompose->expects($this->once())->method('up');
        $this->dockerCompose->method('getLatestLogLine')->willReturn('Still starting...');

        // Service never becomes healthy
        $this->healthChecker->method('getHealthStatus')->willReturn('starting');

        $this->expectException(\Ngramx\Docker\Exception\ServiceNotHealthyException::class);
        $this->expectExceptionMessageMatches('/did not become ready/');

        $orchestrator = $this->createOrchestrator();
        $orchestrator->setup($config);
    }

    public function test_setup_fails_fast_when_monitored_service_is_crash_looping(): void
    {
        $config = $this->createConfig(waitFor: [
            new ServiceWaitConfig(service: 'db', timeout: 30),
        ]);

        $this->dockerCompose->method('hasExistingImages')->willReturn(true);
        $this->dockerCompose->expects($this->once())->method('up');
        $this->dockerCompose->method('getLatestLogLine')->willReturn(null);
        $this->dockerCompose->method('listServices')->willReturn(['db', 'app', 'nginx']);

        // db would be healthy immediately, but nginx is crash-looping because
        // the app container never came up. The waiter must surface this rather
        // than declaring the environment ready.
        $this->healthChecker->method('getHealthStatus')
            ->willReturnCallback(function (string $composeFile, string $service): string {
                return match ($service) {
                    'db' => 'healthy',
                    'app' => 'running',
                    'nginx' => 'restarting',
                    default => 'unknown',
                };
            });

        $this->expectException(\Ngramx\Docker\Exception\ServiceNotHealthyException::class);
        $this->expectExceptionMessageMatches('/nginx \(restarting\)/');

        $orchestrator = $this->createOrchestrator();
        $orchestrator->setup($config);
    }

    public function test_setup_still_verifies_services_when_wait_for_is_empty(): void
    {
        $config = $this->createConfig();

        $this->dockerCompose->method('hasExistingImages')->willReturn(true);
        $this->dockerCompose->expects($this->once())->method('up');
        $this->dockerCompose->method('listServices')->willReturn(['app', 'nginx']);

        // No wait_for configured, but app is crash-looping — ngramx must still
        // catch this instead of blindly declaring success.
        $this->healthChecker->method('getHealthStatus')
            ->willReturnCallback(function (string $composeFile, string $service): string {
                return $service === 'app' ? 'restarting' : 'running';
            });

        $this->expectException(\Ngramx\Docker\Exception\ServiceNotHealthyException::class);
        $this->expectExceptionMessageMatches('/app \(restarting\)/');

        $orchestrator = $this->createOrchestrator();
        $orchestrator->setup($config);
    }

    public function test_first_run_multiplies_timeout_by_10(): void
    {
        $config = $this->createConfig(waitFor: [
            new ServiceWaitConfig(service: 'db', timeout: 1),
        ]);

        $this->dockerCompose->method('hasExistingImages')->willReturn(false);
        $this->dockerCompose->expects($this->once())->method('up');
        $this->dockerCompose->method('getLatestLogLine')->willReturn(null);

        $callCount = 0;
        $this->healthChecker->method('getHealthStatus')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                // Return healthy on 2nd call (after 2s sleep which is within the 10x=10s timeout)
                return $callCount >= 2 ? 'healthy' : 'starting';
            });

        $orchestrator = $this->createOrchestrator();
        $result = $orchestrator->setup($config);

        // If timeout weren't extended, this would throw ServiceNotHealthyException
        // since the service takes >1s but timeout*10=10s allows it
        $this->assertGreaterThanOrEqual(0.0, $result['time']);
    }

    private function createOrchestrator(
        ?AppUrlProbe $appUrlProbe = null,
        ?NetworkAttachmentChecker $checker = null,
    ): SetupOrchestrator {
        return new SetupOrchestrator(
            $this->dockerCompose,
            $this->hostExecutor,
            $this->healthChecker,
            $this->formatter,
            readinessWaiter: null,
            appUrlProbe: $appUrlProbe ?? $this->disabledProbe(),
            networkAttachmentChecker: $checker ?? $this->cleanChecker(),
            // Tests use 1 attempt with no retry sleep so failure-path tests
            // don't sit blocked on the 60s production retry budget.
            appUrlProbeAttempts: 1,
            appUrlProbeRetrySeconds: 0,
        );
    }

    /**
     * Default checker that reports no network issues, so the bulk of tests
     * that aren't about the network-reconcile phase don't have to stub it.
     */
    private function cleanChecker(): NetworkAttachmentChecker
    {
        $checker = $this->createMock(NetworkAttachmentChecker::class);
        $checker->method('checkAll')->willReturn([]);
        $checker->method('checkService')->willReturn(null);
        return $checker;
    }

    /**
     * Most tests in this class predate the URL-probe phase and assert on
     * Docker readiness behaviour, so we hand them a probe that returns
     * "healthy" without doing any real HTTP work.
     */
    private function disabledProbe(): AppUrlProbe
    {
        return new AppUrlProbe(static fn () => new Response(200));
    }

    /**
     * @param ServiceWaitConfig[] $waitFor
     */
    private function createConfig(array $waitFor = []): NgramxConfig
    {
        return new NgramxConfig(
            version: '1.0',
            docker: new DockerConfig(
                composeFile: 'docker-compose.yml',
                primaryService: 'app',
                appUrl: 'http://localhost:80',
                waitFor: $waitFor,
            ),
            setup: new SetupConfig(preStart: [], initialize: []),
            n8n: new N8nConfig(workflowsDir: './.n8n'),
            commands: [],
        );
    }
}
