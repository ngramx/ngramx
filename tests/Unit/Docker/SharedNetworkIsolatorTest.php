<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Docker;

use Ngramx\Docker\SharedNetworkIsolator;
use PHPUnit\Framework\TestCase;

class SharedNetworkIsolatorTest extends TestCase
{
    /** @var list<list<string>> */
    private array $calls = [];

    /**
     * @param array<string, array{0: string, 1: array<string, mixed>}> $containers name => [service, networks]
     * @param array<string, mixed>|null $config
     */
    private function isolator(array $containers, ?array $config = null, int $disconnectExit = 0): SharedNetworkIsolator
    {
        $config ??= [
            'networks' => ['default' => ['name' => 'ns_default'], 'shared' => ['name' => 'cortex-coder', 'external' => true]],
            'services' => [
                'nginx' => ['ports' => [['target' => 80, 'published' => '8081']]],
                'app' => [],
                'db' => ['ports' => [['target' => 3306]]],
            ],
        ];

        return new SharedNetworkIsolator(function (array $cmd) use ($containers, $config, $disconnectExit): array {
            $this->calls[] = $cmd;
            if ($cmd[0] === 'docker-compose') {
                return [0, (string) json_encode($config)];
            }
            if ($cmd[1] === 'ps') {
                return [0, implode("\n", array_keys($containers)) . "\n"];
            }
            if ($cmd[1] === 'inspect') {
                $c = $containers[end($cmd)];
                return [0, $c[0] . "\t" . json_encode($c[1])];
            }
            if ($cmd[1] === 'network') {
                return [$disconnectExit, ''];
            }

            return [0, ''];
        });
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    private function stack(): array
    {
        $both = ['ns_default' => [], 'cortex-coder' => []];

        return [
            'ns-nginx-1' => ['nginx', $both],
            'ns-app-1' => ['app', $both],
            'ns-db-1' => ['db', $both],
        ];
    }

    public function test_disconnects_every_non_web_container_and_keeps_web(): void
    {
        $result = $this->isolator($this->stack())->isolate('docker-compose.yml', 'ns');

        $this->assertSame(['ns-app-1 (cortex-coder)', 'ns-db-1 (cortex-coder)'], $result);
        $this->assertContains(['docker', 'network', 'disconnect', 'cortex-coder', 'ns-app-1'], $this->calls);
        $this->assertNotContains(['docker', 'network', 'disconnect', 'cortex-coder', 'ns-nginx-1'], $this->calls);
    }

    public function test_reloads_nginx_after_disconnecting(): void
    {
        $this->isolator($this->stack())->isolate('docker-compose.yml', 'ns');

        $this->assertContains(['docker', 'exec', 'ns-nginx-1', 'nginx', '-s', 'reload'], $this->calls);
    }

    public function test_already_isolated_stack_reports_nothing_and_does_not_reload(): void
    {
        $stack = $this->stack();
        $stack['ns-app-1'][1] = ['ns_default' => []];
        $stack['ns-db-1'][1] = ['ns_default' => []];

        $result = $this->isolator($stack)->isolate('docker-compose.yml', 'ns');

        $this->assertSame([], $result);
        $this->assertNotContains(['docker', 'exec', 'ns-nginx-1', 'nginx', '-s', 'reload'], $this->calls);
    }

    public function test_never_strands_a_container_whose_only_network_is_shared(): void
    {
        $stack = $this->stack();
        $stack['ns-app-1'][1] = ['cortex-coder' => []];

        $result = $this->isolator($stack)->isolate('docker-compose.yml', 'ns');

        $this->assertSame(['ns-db-1 (cortex-coder)'], $result);
    }

    public function test_does_nothing_without_external_networks(): void
    {
        $config = ['networks' => ['default' => []], 'services' => ['nginx' => ['ports' => ['80:80']]]];

        $this->assertSame([], $this->isolator($this->stack(), $config)->isolate('docker-compose.yml', 'ns'));
    }

    public function test_does_nothing_when_no_web_service_can_be_identified(): void
    {
        $config = ['networks' => ['s' => ['name' => 'cortex-coder', 'external' => true]], 'services' => ['app' => []]];

        $this->assertSame([], $this->isolator($this->stack(), $config)->isolate('docker-compose.yml', 'ns'));
    }

    public function test_recognises_short_syntax_port_mappings(): void
    {
        $config = [
            'networks' => ['s' => ['name' => 'cortex-coder', 'external' => true]],
            'services' => ['nginx' => ['ports' => ['127.0.0.1:8080:443/tcp']], 'app' => [], 'db' => []],
        ];

        $result = $this->isolator($this->stack(), $config)->isolate('docker-compose.yml', 'ns');

        $this->assertSame(['ns-app-1 (cortex-coder)', 'ns-db-1 (cortex-coder)'], $result);
    }

    public function test_failed_disconnect_is_not_reported(): void
    {
        $this->assertSame([], $this->isolator($this->stack(), null, 1)->isolate('docker-compose.yml', 'ns'));
    }
}
