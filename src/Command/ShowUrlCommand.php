<?php

declare(strict_types=1);

namespace Ngramx\Command;

use Ngramx\Config\ConfigLoader;
use Ngramx\Config\Exception\ConfigException;
use Ngramx\Config\LockFile;
use Ngramx\Docker\PortOffsetManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class ShowUrlCommand extends Command
{
    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly LockFile $lockFile,
        private readonly PortOffsetManager $portOffsetManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('show-url')
            ->setDescription('Display the URL for the development environment');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            // Load configuration
            $configPath = $this->configLoader->findConfigFile();
            $config = $this->configLoader->load($configPath);

            // Get base URL from config
            $appUrl = $config->docker->appUrl;

            // Get lock file data if it exists
            $portOffset = 0;
            $lockData = null;
            if ($this->lockFile->exists()) {
                $lockData = $this->lockFile->read();
                $portOffset = $lockData->portOffset ?? 0;
            }

            // When noHostMapping is enabled with a namespace, build internal Docker network URL
            if ($lockData !== null && $lockData->noHostMapping && $lockData->namespace !== null) {
                $httpServiceInfo = $this->findHttpServiceInfo($config->docker->composeFile);
                if ($httpServiceInfo !== null) {
                    $containerName = $lockData->namespace . '-' . $httpServiceInfo['container_name'];
                    $url = "http://{$containerName}:{$httpServiceInfo['internal_port']}";
                    $output->writeln($url);
                    return Command::SUCCESS;
                }
            }

            // Get the primary service's base port
            $basePort = $this->portOffsetManager->getPrimaryServicePort(
                $config->docker->composeFile,
                $config->docker->primaryService
            );

            // Build the URL
            if ($basePort !== null) {
                $finalPort = $basePort + $portOffset;
                // Parse URL and replace/add port
                $url = $this->buildUrlWithPort($appUrl, $finalPort);
            } else {
                // No port exposed, use app_url as-is
                $url = $appUrl;
            }

            // Output plain URL for easy piping
            $output->writeln($url);

            return Command::SUCCESS;
        } catch (ConfigException $e) {
            $output->writeln("<error>Configuration error: {$e->getMessage()}</error>");
            return Command::FAILURE;
        } catch (\Exception $e) {
            $output->writeln("<error>Error: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }

    /**
     * Build URL with the specified port
     */
    private function buildUrlWithPort(string $baseUrl, int $port): string
    {
        $parsed = parse_url($baseUrl);

        $scheme = $parsed['scheme'] ?? 'http';
        $host = $parsed['host'] ?? 'localhost';
        $path = $parsed['path'] ?? '';

        return "{$scheme}://{$host}:{$port}{$path}";
    }

    /**
     * Find the HTTP service info (container name and internal port)
     *
     * Looks for services exposing port 80, 443, or 8080 (common HTTP ports)
     *
     * @return array{container_name: string, internal_port: int}|null
     */
    private function findHttpServiceInfo(string $composeFile): ?array
    {
        if (!file_exists($composeFile)) {
            return null;
        }

        $content = file_get_contents($composeFile);
        if ($content === false) {
            return null;
        }

        $config = Yaml::parse($content);

        if (!isset($config['services'])) {
            return null;
        }

        // Priority order for HTTP ports
        $httpPorts = [80, 443, 8080, 8000, 3000];

        foreach ($httpPorts as $targetPort) {
            foreach ($config['services'] as $serviceName => $service) {
                if (!isset($service['ports']) || !isset($service['container_name'])) {
                    continue;
                }

                foreach ($service['ports'] as $portMapping) {
                    $internalPort = $this->parseInternalPort($portMapping);
                    if ($internalPort === $targetPort) {
                        return [
                            'container_name' => $service['container_name'],
                            'internal_port' => $internalPort,
                        ];
                    }
                }
            }
        }

        // Fallback: return first service with container_name and ports
        foreach ($config['services'] as $serviceName => $service) {
            if (isset($service['ports']) && isset($service['container_name']) && !empty($service['ports'])) {
                $internalPort = $this->parseInternalPort($service['ports'][0]);
                if ($internalPort !== null) {
                    return [
                        'container_name' => $service['container_name'],
                        'internal_port' => $internalPort,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Parse internal (container) port from a port mapping
     *
     * @param mixed $portMapping
     * @return int|null
     */
    private function parseInternalPort(mixed $portMapping): ?int
    {
        if (is_string($portMapping)) {
            $parts = explode(':', $portMapping);

            if (count($parts) === 2) {
                // "80:80" format - internal port is second
                return (int) explode('/', $parts[1])[0]; // Handle "80/tcp"
            } elseif (count($parts) === 3) {
                // "127.0.0.1:80:80" format - internal port is third
                return (int) explode('/', $parts[2])[0];
            } elseif (count($parts) === 1) {
                // "80" format - same port for host and container
                return (int) explode('/', $parts[0])[0];
            }
        } elseif (is_array($portMapping)) {
            // Long format: {target: 80, published: 8080}
            return isset($portMapping['target']) ? (int) $portMapping['target'] : null;
        }

        return null;
    }
}
