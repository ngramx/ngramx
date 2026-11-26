<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Docker;

use Ngramx\Docker\SharedNetworkIsolator;
use PHPUnit\Framework\TestCase;

class SharedNetworkIsolatorTest extends TestCase
{
    private const RELOAD_NGINX = ['docker', 'exec', 'ns-nginx-1', 'nginx', '-s', 'reload'];

    /** @var list<list<string>> */
    private array $calls = [];

    /**
     * @param array<string, array{0: list<string>, 1: array<string, mixed>, 2?: list<string>}> $containers
     *        name => [exposed ports, networks, published ports]
     * @param array<string, mixed>|null $config
     */
    private function isolator(array $containers, ?array $config = null, int $disconnectExit = 0): SharedNetworkIsolator
    {
        $config ??= [
            'networks' => ['default' => ['name' => 'ns_default'], 'shared' => ['name' => 'cortex-coder', 'external' => true]],
            'services' => ['nginx' => [], 'app' => [], 'db' => []],
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
                [$exposed, $networks, $published] = $containers[end($cmd)] + [2 => []];
                $ports = static fn (array $p): string => $p === [] ? 'null' : (string) json_encode(array_fill_keys($p, new \stdClass()));

                return [0, json_encode($networks) . "\t" . $ports($published) . "\t" . $ports($exposed)];
            }
            if ($cmd[1] === 'network') {
                return [$disconnectExit, ''];
            }

            return [0, ''];
        });
    }

    /**
     * @return array<string, array{0: list<string>, 1: array<string, mixed>, 2?: list<string>}>
     */
    private function stack(): array
    {
        $both = ['ns_default' => [], 'cortex-coder' => []];

        return [
            'ns-nginx-1' => [['80/tcp'], $both],
            'ns-app-1' => [['9000/tcp'], $both],
            'ns-db-1' => [['3306/tcp'], $both],
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

        $this->assertContains(self::RELOAD_NGINX, $this->calls);
    }

    public function test_already_isolated_stack_reports_nothing_but_still_reloads_nginx(): void
    {
        $stack = $this->stack();
        $stack['ns-app-1'][1] = ['ns_default' => []];
        $stack['ns-db-1'][1] = ['ns_default' => []];

        $result = $this->isolator($stack)->isolate('docker-compose.yml', 'ns');

        $this->assertSame([], $result);
        // nginx may have cached another stack's `app` when it was (re)created.
        $this->assertContains(self::RELOAD_NGINX, $this->calls);
    }

    public function test_detects_web_container_without_published_ports(): void
    {
        // `--no-host-mapping` clears `ports` from the compose config; the web
        // container still exposes 80 and must be the one that stays attached.
        $config = [
            'networks' => ['s' => ['name' => 'cortex-coder', 'external' => true]],
            'services' => ['nginx' => [], 'app' => [], 'db' => []],
        ];

        $result = $this->isolator($this->stack(), $config)->isolate('docker-compose.yml', 'ns');

        $this->assertSame(['ns-app-1 (cortex-coder)', 'ns-db-1 (cortex-coder)'], $result);
    }

    public function test_published_web_port_wins_over_images_that_merely_expose_80(): void
    {
        $stack = $this->stack();
        $stack['ns-nginx-1'][2] = ['80/tcp'];
        $stack['ns-app-1'][0] = ['80/tcp', '9000/tcp'];

        $result = $this->isolator($stack)->isolate('docker-compose.yml', 'ns');

        $this->assertSame(['ns-app-1 (cortex-coder)', 'ns-db-1 (cortex-coder)'], $result);
    }

    public function test_keeps_every_container_when_several_expose_a_web_port_and_none_publish(): void
    {
        $stack = $this->stack();
        $stack['ns-app-1'][0] = ['80/tcp'];

        $result = $this->isolator($stack)->isolate('docker-compose.yml', 'ns');

        $this->assertSame(['ns-db-1 (cortex-coder)'], $result);
    }

    public function test_recognises_443_as_a_web_port(): void
    {
        $stack = $this->stack();
        $stack['ns-nginx-1'][0] = ['443/tcp'];

        $result = $this->isolator($stack)->isolate('docker-compose.yml', 'ns');

        $this->assertSame(['ns-app-1 (cortex-coder)', 'ns-db-1 (cortex-coder)'], $result);
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
        $config = ['networks' => ['default' => []], 'services' => ['nginx' => []]];

        $this->assertSame([], $this->isolator($this->stack(), $config)->isolate('docker-compose.yml', 'ns'));
        $this->assertNotContains(self::RELOAD_NGINX, $this->calls);
    }

    public function test_does_nothing_when_no_web_container_can_be_identified(): void
    {
        $stack = $this->stack();
        $stack['ns-nginx-1'][0] = ['8080/tcp'];

        $this->assertSame([], $this->isolator($stack)->isolate('docker-compose.yml', 'ns'));
    }

    public function test_failed_disconnect_is_not_reported(): void
    {
        $this->assertSame([], $this->isolator($this->stack(), null, 1)->isolate('docker-compose.yml', 'ns'));
    }
}
