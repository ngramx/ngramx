<?php

declare(strict_types=1);

namespace Ngramx\Docker;

use Symfony\Component\Yaml\Yaml;

/**
 * Manages port offset allocation and conflict detection
 */
class PortOffsetManager
{
    private const DEFAULT_SCAN_START = 8000;
    private const DEFAULT_SCAN_END = 9000;

    /**
     * Extract base ports from docker-compose.yml
     *
     * @return int[] Array of base port numbers
     */
    public function extractBasePorts(string $composeFile): array
    {
        if (!file_exists($composeFile)) {
            return [];
        }

        $content = file_get_contents($composeFile);
        if ($content === false) {
            return [];
        }

        $config = Yaml::parse($content);

        $ports = [];

        if (!isset($config['services'])) {
            return [];
        }

        foreach ($config['services'] as $service) {
            if (!isset($service['ports'])) {
                continue;
            }

            foreach ($service['ports'] as $portMapping) {
                $port = $this->parsePortMapping($portMapping);
                if ($port !== null) {
                    $ports[] = $port;
                }
            }
        }

        return array_unique($ports);
    }

    /**
     * Find an available port offset by scanning for conflicts
     *
     * @param int[] $basePorts Base ports to check
     * @return int Available port offset
     * @throws \RuntimeException If no available offset found
     */
    public function findAvailableOffset(array $basePorts): int
    {
        if (empty($basePorts)) {
            return 0;
        }

        // First check if base ports are available (offset = 0)
        if ($this->arePortsAvailable($basePorts, 0)) {
            return 0;
        }

        // Scan for available offset with longer timeout to allow port release
        // Wait a bit longer after cleanup before scanning
        usleep(200000); // 200ms additional wait

        for ($offset = self::DEFAULT_SCAN_START; $offset <= self::DEFAULT_SCAN_END; $offset += 100) {
            if ($this->arePortsAvailable($basePorts, $offset)) {
                return $offset;
            }
        }

        throw new \RuntimeException(
            sprintf(
                'No available port offset found in range %d-%d. Please stop other services or specify a custom --port-offset.',
                self::DEFAULT_SCAN_START,
                self::DEFAULT_SCAN_END
            )
        );
    }

    /**
     * Check if all ports with given offset are available
     *
     * @param int[] $basePorts
     * @param int $offset
     * @return bool
     */
    private function arePortsAvailable(array $basePorts, int $offset): bool
    {
        foreach ($basePorts as $basePort) {
            $port = $basePort + $offset;
            if (!$this->isPortAvailable($port)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check if a specific port is available on the host
     * Uses Docker to check actual host port bindings (works even when running in a container)
     */
    private function isPortAvailable(int $port): bool
    {
        // Get all used ports from Docker containers on the host
        static $usedPorts = null;

        if ($usedPorts === null) {
            $usedPorts = $this->getDockerUsedPorts();
        }

        // Check if this port is in the used ports list
        return !in_array($port, $usedPorts, true);
    }

    /**
     * Get all ports currently in use by Docker containers on the host
     *
     * @return int[]
     */
    private function getDockerUsedPorts(): array
    {
        $process = new \Symfony\Component\Process\Process([
            'docker',
            'ps',
            '--format',
            '{{.Ports}}',
        ]);
        $process->run();

        if (!$process->isSuccessful()) {
            // Fallback to socket-based checking if Docker query fails
            return [];
        }

        $output = trim($process->getOutput());
        if (empty($output)) {
            return [];
        }

        $usedPorts = [];
        foreach (explode("\n", $output) as $line) {
            // Parse port bindings like:
            // "0.0.0.0:8080->80/tcp"
            // "127.0.0.1:8080->80/tcp, 0.0.0.0:5432->5432/tcp"
            // "[::]:8080->80/tcp"
            preg_match_all('/:(\d+)->/', $line, $matches);
            if (!empty($matches[1])) {
                foreach ($matches[1] as $port) {
                    $usedPorts[] = (int) $port;
                }
            }
        }

        return array_unique($usedPorts);
    }

    /**
     * Get the host port for a specific service
     *
     * @return int|null The host port or null if no port exposed
     */
    public function getPrimaryServicePort(string $composeFile, string $serviceName): ?int
    {
        if (!file_exists($composeFile)) {
            return null;
        }

        $content = file_get_contents($composeFile);
        if ($content === false) {
            return null;
        }

        $config = Yaml::parse($content);

        if (!isset($config['services'][$serviceName]['ports'])) {
            return null;
        }

        $ports = $config['services'][$serviceName]['ports'];
        if (empty($ports)) {
            return null;
        }

        // Return the first host port
        return $this->parsePortMapping($ports[0]);
    }

    /**
     * Parse port mapping string to extract host port number
     *
     * Supports formats:
     *   - "80:80"
     *   - "8080:80"
     *   - "127.0.0.1:8080:80"
     *   - Array format: {"target": 80, "published": 8080}
     */
    private function parsePortMapping(mixed $portMapping): ?int
    {
        if (is_string($portMapping)) {
            // Split on top-level colons only so env-var interpolation in the
            // host port (e.g. "${VAR:-3827}:4173") is not corrupted.
            $parts = PortMapping::split($portMapping);

            if (count($parts) === 2) {
                // "80:80" format - host port is first
                return PortMapping::hostPortNumber($parts[0]);
            } elseif (count($parts) === 3) {
                // "127.0.0.1:80:80" format - host port is second
                return PortMapping::hostPortNumber($parts[1]);
            }
        } elseif (is_array($portMapping)) {
            // Long format: {target: 80, published: 8080}
            return isset($portMapping['published']) ? (int) $portMapping['published'] : null;
        }

        return null;
    }
}
