<?php

declare(strict_types=1);

namespace Ngramx\Command;

use Ngramx\Config\ConfigLoader;
use Ngramx\Config\Exception\ConfigException;
use Ngramx\Config\LockFile;
use Ngramx\Config\LockFileData;
use Ngramx\Docker\PortOffsetManager;
use Ngramx\Http\EndpointUrls;
use Ngramx\Worktree\WorktreeUrlResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class ShowUrlCommand extends Command
{
    private readonly WorktreeUrlResolver $worktreeUrlResolver;

    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly LockFile $lockFile,
        private readonly PortOffsetManager $portOffsetManager,
        ?WorktreeUrlResolver $worktreeUrlResolver = null,
    ) {
        parent::__construct();
        // One quick baseline attempt: by the time anyone runs `show-url` the
        // stack is up, so retrying a slow host only adds latency to what should
        // be a one-line, pipeable answer.
        $this->worktreeUrlResolver = $worktreeUrlResolver ?? new WorktreeUrlResolver(baselineAttempts: 1);
    }

    protected function configure(): void
    {
        $this
            ->setName('show-url')
            ->setAliases(['url'])
            ->setDescription('Display the URL for the development environment')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'List every endpoint (docker.app_url plus docker.endpoints.*) as "name<TAB>url" lines')
            ->addOption('endpoint', 'e', InputOption::VALUE_REQUIRED, 'Print the URL of one named endpoint from docker.endpoints (or "primary")')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print all endpoints as a JSON object of name => url');
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

            $endpointOption = $input->getOption('endpoint');
            $wantsEndpoints = (bool) $input->getOption('all') || (bool) $input->getOption('json') || is_string($endpointOption);

            // A running environment records the URL it was advertised on. That
            // is the authoritative answer — notably for worktrees, where the
            // hostname was decided by probing the live app — so prefer it over
            // anything we can re-derive from config.
            if ($lockData !== null && $lockData->url !== null && $lockData->url !== '' && !$wantsEndpoints) {
                $output->writeln($lockData->url);
                return Command::SUCCESS;
            }

            if ($wantsEndpoints) {
                // Endpoints follow the same offset/remap as the primary; a lock
                // file's recorded URLs (worktree hostnames) win where present.
                $shifted = EndpointUrls::shifted($config->docker, $portOffset, $lockData->portMap ?? []);
                $urls = $lockData !== null
                    ? EndpointUrls::fromRecorded($lockData->url, $lockData->urls, $shifted)
                    : $shifted;

                return $this->printEndpoints($input, $output, $urls, is_string($endpointOption) ? $endpointOption : null);
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

            $url = $this->buildUrl($appUrl, $config, $lockData, $portOffset);

            // A worktree environment usually lives on its own
            // "<folder>.localhost" origin, decided at `ngramx review` time by
            // asking the running app whether it answers to that hostname. Older
            // environments predate the URL being recorded in the lock file, so
            // re-run the same decision here rather than printing the shared
            // canonical host, which would point at the main checkout's stack.
            $worktreeFolder = $this->worktreeFolderName(dirname($configPath));
            if ($worktreeFolder !== null && $lockData !== null) {
                $url = $this->worktreeUrlResolver->resolve($url, $worktreeFolder, 0);
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

    private function printEndpoints(InputInterface $input, OutputInterface $output, EndpointUrls $urls, ?string $endpoint): int
    {
        if ($endpoint !== null) {
            $url = $urls->get($endpoint);
            if ($url === null) {
                $known = implode(', ', array_keys($urls->all()));
                $output->writeln("<error>Unknown endpoint \"{$endpoint}\". Known endpoints: {$known}</error>");
                return Command::FAILURE;
            }
            $output->writeln($url);
            return Command::SUCCESS;
        }

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($urls->all(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        foreach ($urls->all() as $name => $url) {
            $output->writeln($name . "\t" . $url);
        }

        return Command::SUCCESS;
    }

    /**
     * Build the host-facing URL: the configured app URL with the host port the
     * environment is actually listening on.
     */
    private function buildUrl(
        string $appUrl,
        \Ngramx\Config\Schema\NgramxConfig $config,
        ?LockFileData $lockData,
        int $portOffset
    ): string {
        $scheme = strtolower((string) (parse_url($appUrl, PHP_URL_SCHEME) ?: 'http'));
        $defaultPort = $this->defaultPortForScheme($scheme);

        $basePort = $this->resolveBasePort($appUrl, $config, $defaultPort);
        if ($basePort === null) {
            return $appUrl;
        }

        // A targeted conflict remap recorded at `up` time takes
        // precedence for its ports; the global offset covers the rest.
        $portMap = $lockData->portMap ?? [];
        $finalPort = $portMap[$basePort] ?? ($basePort + $portOffset);

        // https://host:443 is just https://host — printing the scheme's own
        // default port back at the user is noise.
        if ($finalPort === $defaultPort) {
            return $this->buildUrlWithoutPort($appUrl);
        }

        return $this->buildUrlWithPort($appUrl, $finalPort);
    }

    /**
     * Work out which host port the offset/remap should be applied to.
     *
     * Order of preference:
     *   1. An explicit port in `docker.app_url` — the project said so.
     *   2. The host port publishing the scheme's default container port
     *      (443 for https, 80 for http), preferring the primary service but
     *      accepting any service: the web port is often published by a proxy
     *      container rather than by the app itself.
     *   3. The primary service's first published port.
     *   4. The scheme default, so an app behind a host-port-less proxy still
     *      gets the offset applied.
     */
    private function resolveBasePort(
        string $appUrl,
        \Ngramx\Config\Schema\NgramxConfig $config,
        ?int $defaultPort
    ): ?int {
        $explicitPort = parse_url($appUrl, PHP_URL_PORT);
        if (is_int($explicitPort)) {
            return $explicitPort;
        }

        if ($defaultPort !== null) {
            $published = $this->portOffsetManager->findHostPortForInternalPort(
                $config->docker->composeFile,
                $defaultPort,
                $config->docker->primaryService,
            );

            if ($published !== null) {
                return $published;
            }
        }

        $primaryPort = $this->portOffsetManager->getPrimaryServicePort(
            $config->docker->composeFile,
            $config->docker->primaryService
        );

        return $primaryPort ?? $defaultPort;
    }

    private function defaultPortForScheme(string $scheme): ?int
    {
        return match ($scheme) {
            'http' => 80,
            'https' => 443,
            default => null,
        };
    }

    /**
     * Return the worktree folder name when $projectDir is a Ngramx worktree
     * (`<repo>/.ngramx/worktrees/<folder>`), or null for a normal checkout.
     */
    private function worktreeFolderName(string $projectDir): ?string
    {
        $normalized = str_replace('\\', '/', rtrim($projectDir, '/\\'));

        if (!str_contains($normalized, '/.ngramx/worktrees/')) {
            return null;
        }

        $folder = basename($normalized);

        return $folder !== '' ? $folder : null;
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
     * Build URL without any port component
     */
    private function buildUrlWithoutPort(string $baseUrl): string
    {
        $parsed = parse_url($baseUrl);

        $scheme = $parsed['scheme'] ?? 'http';
        $host = $parsed['host'] ?? 'localhost';
        $path = $parsed['path'] ?? '';

        return "{$scheme}://{$host}{$path}";
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
