<?php

declare(strict_types=1);

namespace Ngramx\Docker;

use Symfony\Component\Process\Process;

/**
 * Keep concurrent worktree stacks from answering each other's requests.
 *
 * A project's compose file may attach services to an external network that
 * every stack on the host shares (Cortex Coder's `cortex-coder`, for example).
 * Docker's embedded DNS answers a service name such as `app` with the
 * containers of *all* stacks on that network, so nginx's `fastcgi_pass app:9000`
 * round-robins between stacks that have different databases and sessions —
 * login then loops between `/login` and `/dashboard`.
 *
 * Compose cannot remove a network from a service in an override file, so after
 * the stack is up we disconnect every non-web container from each external
 * network. The web-facing container (the service publishing port 80/443) stays
 * attached because outside tooling reaches the stack through it. Every other
 * service name then resolves on the stack's own project network only.
 *
 * The operation is idempotent: a stack that is already isolated (by this class
 * or by Cortex Coder's equivalent step) has nothing left to disconnect.
 */
class SharedNetworkIsolator
{
    private const WEB_PORTS = [80, 443];

    /** @var callable(list<string>): array{0: int, 1: string} */
    private $runner;

    /**
     * @param (callable(list<string>): array{0: int, 1: string})|null $runner
     *        Runs a command and returns [exitCode, stdout]. Injectable for tests.
     */
    public function __construct(?callable $runner = null)
    {
        $this->runner = $runner ?? static function (array $command): array {
            $process = new Process($command);
            $process->setTimeout(30);
            $process->run();

            return [$process->getExitCode() ?? 1, $process->getOutput()];
        };
    }

    /**
     * Disconnect non-web containers of the namespaced stack from every
     * external network the compose file declares.
     *
     * @return list<string> "<container> (<network>)" for each disconnect performed
     */
    public function isolate(string $composeFile, string $namespace): array
    {
        $config = $this->composeConfig($composeFile, $namespace);
        if ($config === null) {
            return [];
        }

        $externalNetworks = $this->externalNetworks($config);
        $webServices = $this->webServices($config);
        if ($externalNetworks === [] || $webServices === []) {
            // Without a web service we cannot tell which container to keep.
            return [];
        }

        $disconnected = [];
        $webContainers = [];

        foreach ($this->projectContainers($namespace) as $container) {
            if (in_array($container['service'], $webServices, true)) {
                $webContainers[] = $container['name'];
                continue;
            }

            $attached = array_keys($container['networks']);
            foreach ($externalNetworks as $network) {
                // Never strand a container with no network at all.
                if (!in_array($network, $attached, true) || count($attached) < 2) {
                    continue;
                }

                [$exit] = ($this->runner)(['docker', 'network', 'disconnect', $network, $container['name']]);
                if ($exit === 0) {
                    $disconnected[] = "{$container['name']} ({$network})";
                    $attached = array_values(array_diff($attached, [$network]));
                }
            }
        }

        if ($disconnected !== []) {
            $this->reloadWebServers($webContainers);
        }

        return $disconnected;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function composeConfig(string $composeFile, string $namespace): ?array
    {
        $command = array_merge(
            ['docker-compose'],
            ComposeFiles::fileArgs($composeFile),
            ['-p', $namespace, 'config', '--format', 'json'],
        );

        [$exit, $output] = ($this->runner)($command);
        if ($exit !== 0) {
            return null;
        }

        $config = json_decode($output, true);

        return is_array($config) ? $config : null;
    }

    /**
     * Real Docker names of the networks the compose file marks `external`.
     *
     * @param array<string, mixed> $config
     * @return list<string>
     */
    private function externalNetworks(array $config): array
    {
        $names = [];
        foreach ((array) ($config['networks'] ?? []) as $key => $network) {
            if (!is_array($network) || empty($network['external'])) {
                continue;
            }
            $names[] = is_string($network['name'] ?? null) && $network['name'] !== '' ? $network['name'] : (string) $key;
        }

        return $names;
    }

    /**
     * Services that publish or expose port 80/443.
     *
     * @param array<string, mixed> $config
     * @return list<string>
     */
    private function webServices(array $config): array
    {
        $services = [];
        foreach ((array) ($config['services'] ?? []) as $name => $service) {
            foreach ((array) ($service['ports'] ?? []) as $port) {
                $target = is_array($port) ? ($port['target'] ?? null) : $port;
                if (is_string($target)) {
                    $parts = explode(':', preg_replace('#/.*$#', '', $target) ?? $target);
                    $target = end($parts);
                }
                if (in_array((int) $target, self::WEB_PORTS, true)) {
                    $services[] = (string) $name;
                    break;
                }
            }
        }

        return $services;
    }

    /**
     * @return list<array{name: string, service: string, networks: array<string, mixed>}>
     */
    private function projectContainers(string $namespace): array
    {
        [$exit, $output] = ($this->runner)([
            'docker', 'ps',
            '--filter', 'label=com.docker.compose.project=' . $namespace,
            '--format', '{{.Names}}',
        ]);
        if ($exit !== 0) {
            return [];
        }

        $containers = [];
        foreach (array_filter(array_map('trim', explode("\n", $output))) as $name) {
            [$exit, $inspect] = ($this->runner)([
                'docker', 'inspect', '--format',
                '{{index .Config.Labels "com.docker.compose.service"}}' . "\t" . '{{json .NetworkSettings.Networks}}',
                $name,
            ]);
            if ($exit !== 0) {
                continue;
            }

            $parts = explode("\t", trim($inspect), 2);
            $networks = count($parts) === 2 ? json_decode($parts[1], true) : null;
            if (!is_array($networks)) {
                continue;
            }

            $containers[] = ['name' => $name, 'service' => $parts[0], 'networks' => $networks];
        }

        return $containers;
    }

    /**
     * nginx resolves upstreams once at config load and caches the address, so
     * after the other containers leave the shared network it would keep
     * proxying to their now-unreachable shared-network IPs. Reloading makes it
     * re-resolve on the project network. Non-fatal: the web server may not be nginx.
     *
     * @param list<string> $webContainers
     */
    private function reloadWebServers(array $webContainers): void
    {
        foreach ($webContainers as $container) {
            ($this->runner)(['docker', 'exec', $container, 'nginx', '-s', 'reload']);
        }
    }
}
