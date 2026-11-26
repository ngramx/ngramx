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
 * network. The web-facing container (the one exposing port 80/443) stays
 * attached because outside tooling reaches the stack through it. Every other
 * service name then resolves on the stack's own project network only.
 *
 * The operation is idempotent: a stack that is already isolated (by this class
 * or by Cortex Coder's equivalent step) has nothing left to disconnect.
 */
class SharedNetworkIsolator
{
    private const WEB_PORTS = ['80/tcp', '443/tcp'];

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
        if ($externalNetworks === []) {
            return [];
        }

        $containers = $this->projectContainers($namespace);
        $webContainers = $this->webContainers($containers);
        if ($webContainers === []) {
            // Without a web container we cannot tell which one to keep.
            return [];
        }

        $disconnected = [];

        foreach ($containers as $container) {
            if (in_array($container['name'], $webContainers, true)) {
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

        // Reload even when nothing was disconnected: this stack's nginx may have
        // been (re)created after its app was isolated, while another stack's
        // `app` was still on the shared network, and cached that address.
        $this->reloadWebServers($webContainers);

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
     * @return list<array{name: string, published: bool, exposed: bool, networks: array<string, mixed>}>
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
                '{{json .NetworkSettings.Networks}}' . "\t" . '{{json .HostConfig.PortBindings}}' . "\t" . '{{json .Config.ExposedPorts}}',
                $name,
            ]);
            if ($exit !== 0) {
                continue;
            }

            $parts = explode("\t", trim($inspect), 3);
            $networks = count($parts) === 3 ? json_decode($parts[0], true) : null;
            if (!is_array($networks)) {
                continue;
            }

            // Read from the container itself, not the compose config:
            // `--no-host-mapping` clears `ports`, but the web server still
            // exposes 80/443.
            $containers[] = [
                'name' => $name,
                'published' => $this->hasWebPort($parts[1]),
                'exposed' => $this->hasWebPort($parts[2]),
                'networks' => $networks,
            ];
        }

        return $containers;
    }

    private function hasWebPort(string $json): bool
    {
        $ports = json_decode($json, true);

        return is_array($ports) && array_intersect(self::WEB_PORTS, array_keys($ports)) !== [];
    }

    /**
     * The containers to keep on the shared network: those publishing 80/443,
     * or when none do (`--no-host-mapping`), those exposing it. If several
     * containers expose it we keep all of them rather than guess.
     *
     * @param list<array{name: string, published: bool, exposed: bool, networks: array<string, mixed>}> $containers
     * @return list<string>
     */
    private function webContainers(array $containers): array
    {
        foreach (['published', 'exposed'] as $tier) {
            $names = array_column(array_filter($containers, static fn (array $c): bool => $c[$tier]), 'name');
            if ($names !== []) {
                return $names;
            }
        }

        return [];
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
