<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Target;

use Ngramx\Config\Schema\Postmaclone\PostmacloneConfig;
use Ngramx\Postmaclone\Connection\ConnectionFactory;
use Ngramx\Postmaclone\Exception\PostmacloneException;
use Ngramx\Postmaclone\PostmacloneLockData;
use Symfony\Component\Process\Process;

class DockerDbTarget implements EphemeralTargetInterface
{
    public function __construct(
        private readonly ?string $image = null,
        private readonly int $hostPort = 0,
        private readonly ?string $composeFile = null,
        private readonly ?string $primaryService = null,
        private readonly ConnectionFactory $connections = new ConnectionFactory(),
        private readonly ComposeNetworkResolver $networks = new ComposeNetworkResolver(),
        private readonly ComposeDbServiceSwitcher $dbSwitcher = new ComposeDbServiceSwitcher(),
    ) {
    }

    public function provision(string $engine, int $ttlHours): EphemeralTarget
    {
        $password = bin2hex(random_bytes(16));
        $database = 'postmaclone';
        $username = 'postmaclone';
        $image = $this->image ?? $this->defaultImage($engine);
        $name = 'ngramx-postmaclone-' . substr(bin2hex(random_bytes(6)), 0, 12);
        $projectNetwork = $this->networks->resolve($this->composeFile, $this->primaryService);
        $dbService = $this->dbSwitcher->detectServiceName($this->composeFile);
        $networkAlias = $this->dbSwitcher->networkAlias($this->composeFile);
        $stoppedDbService = null;

        // Free the compose DNS name (usually `db`) before we claim the alias.
        if ($this->composeFile !== null && $dbService !== null && $projectNetwork !== null) {
            $this->dbSwitcher->stop($this->composeFile, $dbService);
            $stoppedDbService = $dbService;
        }

        if ($engine === PostmacloneConfig::ENGINE_POSTGRES) {
            $containerPort = 5432;
            $env = [
                'POSTGRES_PASSWORD=' . $password,
                'POSTGRES_USER=' . $username,
                'POSTGRES_DB=' . $database,
            ];
            $ready = ['pg_isready', '-U', $username, '-d', $database];
        } else {
            $containerPort = 3306;
            $env = [
                'MYSQL_ROOT_PASSWORD=' . $password,
                'MYSQL_DATABASE=' . $database,
                'MYSQL_USER=' . $username,
                'MYSQL_PASSWORD=' . $password,
            ];
            $ready = ['mysqladmin', 'ping', '-h', '127.0.0.1', '-u' . $username, '-p' . $password];
        }

        $publish = $this->hostPort > 0 ? $this->hostPort . ':' . $containerPort : $containerPort;

        $cmd = [
            'docker', 'run', '-d',
            '--name', $name,
            '-p', $publish,
            '--label', 'ngramx.postmaclone=1',
        ];
        if ($projectNetwork !== null) {
            $cmd[] = '--network';
            $cmd[] = $projectNetwork;
            $cmd[] = '--network-alias';
            $cmd[] = $networkAlias;
        }
        foreach ($env as $e) {
            $cmd[] = '-e';
            $cmd[] = $e;
        }
        $cmd[] = $image;

        $process = new Process($cmd);
        $process->setTimeout(120);
        $process->run();
        if (!$process->isSuccessful()) {
            $this->bestEffortRestartDb($stoppedDbService);
            throw new PostmacloneException('Failed to start Docker DB: ' . $process->getErrorOutput());
        }

        $containerId = trim($process->getOutput());

        if ($projectNetwork === null) {
            $projectNetwork = $this->networks->resolve($this->composeFile, $this->primaryService);
            if ($projectNetwork !== null) {
                if ($this->composeFile !== null && $dbService !== null && $stoppedDbService === null) {
                    $this->dbSwitcher->stop($this->composeFile, $dbService);
                    $stoppedDbService = $dbService;
                }
                $this->connectNetwork($name, $projectNetwork, $networkAlias);
            }
        }

        try {
            $hostPort = $this->resolveHostPort($name, $containerPort);
            $this->waitReady($name, $ready);

            $hostUrl = $this->connections->buildUrl(
                $engine === 'postgres' ? 'postgres' : 'mysql',
                '127.0.0.1',
                $hostPort,
                $database,
                $username,
                $password,
            );
            $this->waitAcceptingQueries($engine, $name, $hostUrl, $username, $database);
        } catch (\Throwable $e) {
            $rm = new Process(['docker', 'rm', '-f', $name]);
            $rm->run();
            $this->bestEffortRestartDb($stoppedDbService);
            throw $e;
        }

        // Apps keep hardcoded DB_HOST=db; we own that DNS name via network alias.
        // Host-side tools use 127.0.0.1 + published port.
        $appHost = $projectNetwork !== null ? $networkAlias : '127.0.0.1';
        $appPort = $projectNetwork !== null ? $containerPort : $hostPort;
        $appUrl = $this->connections->buildUrl(
            $engine === 'postgres' ? 'postgres' : 'mysql',
            $appHost,
            $appPort,
            $database,
            $username,
            $password,
        );

        $expires = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . max(1, $ttlHours) . ' hours')
            ->format('c');

        return new EphemeralTarget(
            provider: 'docker',
            engine: $engine,
            host: $appHost,
            port: $appPort,
            database: $database,
            username: $username,
            password: $password,
            databaseUrl: $appUrl,
            expiresAt: $expires,
            meta: [
                'container_id' => $containerId,
                'container_name' => $name,
                'compose_network' => $projectNetwork,
                'network_alias' => $networkAlias,
                'stopped_db_service' => $stoppedDbService,
                'compose_file' => $this->composeFile,
                'host_bind_host' => '127.0.0.1',
                'host_bind_port' => $hostPort,
                'host_bind_url' => $hostUrl,
            ],
        );
    }

    public function destroy(PostmacloneLockData $lock): void
    {
        $name = (string) ($lock->providerMeta['container_name'] ?? '');
        $id = (string) ($lock->providerMeta['container_id'] ?? '');
        $target = $name !== '' ? $name : $id;
        if ($target !== '') {
            $process = new Process(['docker', 'rm', '-f', $target]);
            $process->setTimeout(60);
            $process->run();
            if (!$process->isSuccessful()) {
                throw new PostmacloneException('Failed to remove Docker DB container: ' . $process->getErrorOutput());
            }
        }

        $stopped = $lock->providerMeta['stopped_db_service'] ?? null;
        $composeFile = $lock->providerMeta['compose_file'] ?? $this->composeFile;
        if (is_string($stopped) && $stopped !== '' && is_string($composeFile) && $composeFile !== '' && is_file($composeFile)) {
            try {
                $this->dbSwitcher->start($composeFile, $stopped);
            } catch (\Throwable $e) {
                throw new PostmacloneException($e->getMessage(), 0, $e);
            }
        }
    }

    private function bestEffortRestartDb(?string $stoppedDbService): void
    {
        if ($stoppedDbService === null || $this->composeFile === null || !is_file($this->composeFile)) {
            return;
        }
        try {
            $this->dbSwitcher->start($this->composeFile, $stoppedDbService);
        } catch (\Throwable) {
            // best-effort during failed provision
        }
    }

    private function connectNetwork(string $containerName, string $network, string $alias): void
    {
        $process = new Process([
            'docker', 'network', 'connect',
            '--alias', $alias,
            $network,
            $containerName,
        ]);
        $process->setTimeout(30);
        $process->run();
        if (!$process->isSuccessful()) {
            $err = $process->getErrorOutput();
            if (!str_contains(strtolower($err), 'already exists') && !str_contains(strtolower($err), 'already connected')) {
                throw new PostmacloneException(
                    "Failed to attach Postmaclone DB to compose network '{$network}' as '{$alias}': {$err}"
                );
            }
        }
    }

    private function defaultImage(string $engine): string
    {
        return match ($engine) {
            PostmacloneConfig::ENGINE_POSTGRES => 'postgres:16-alpine',
            PostmacloneConfig::ENGINE_MARIADB => 'mariadb:11',
            default => 'mysql:8.0',
        };
    }

    private function resolveHostPort(string $containerName, int $containerPort): int
    {
        $process = new Process([
            'docker', 'inspect',
            '--format', '{{(index (index .NetworkSettings.Ports "' . $containerPort . '/tcp") 0).HostPort}}',
            $containerName,
        ]);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new PostmacloneException('Failed to resolve Docker published port: ' . $process->getErrorOutput());
        }
        $port = (int) trim($process->getOutput());
        if ($port <= 0) {
            throw new PostmacloneException('Docker published port was empty');
        }

        return $port;
    }

    /**
     * @param list<string> $ready
     */
    private function waitReady(string $containerName, array $ready): void
    {
        $deadline = time() + 120;
        while (time() < $deadline) {
            $running = new Process(['docker', 'inspect', '-f', '{{.State.Running}}', $containerName]);
            $running->run();
            if (trim($running->getOutput()) !== 'true') {
                $logs = new Process(['docker', 'logs', '--tail', '40', $containerName]);
                $logs->run();
                throw new PostmacloneException(
                    "Docker database container '{$containerName}' is not running.\n" . $logs->getOutput()
                );
            }

            $cmd = array_merge(['docker', 'exec', $containerName], $ready);
            $process = new Process($cmd);
            $process->run();
            if ($process->isSuccessful()) {
                return;
            }
            usleep(500_000);
        }

        throw new PostmacloneException("Docker database container '{$containerName}' did not become ready in time");
    }

    private function waitAcceptingQueries(
        string $engine,
        string $containerName,
        string $hostDatabaseUrl,
        string $username,
        string $database,
    ): void {
        $deadline = time() + 60;
        $lastError = '';
        while (time() < $deadline) {
            if ($engine === PostmacloneConfig::ENGINE_POSTGRES) {
                $process = new Process([
                    'docker', 'exec', $containerName,
                    'psql', '-U', $username, '-d', $database, '-v', 'ON_ERROR_STOP=1', '-c', 'SELECT 1',
                ]);
            } else {
                try {
                    $this->connections->fromUrl($hostDatabaseUrl)->query('SELECT 1');

                    return;
                } catch (\Throwable $e) {
                    $lastError = $e->getMessage();
                    usleep(500_000);
                    continue;
                }
            }

            $process->run();
            if ($process->isSuccessful()) {
                $hostProbe = new Process([
                    'psql', $hostDatabaseUrl, '-v', 'ON_ERROR_STOP=1', '-c', 'SELECT 1',
                ]);
                $hostProbe->run();
                if ($hostProbe->isSuccessful()) {
                    return;
                }
                $lastError = $hostProbe->getErrorOutput() ?: $hostProbe->getOutput();
                usleep(500_000);
                continue;
            }
            $lastError = $process->getErrorOutput() ?: $process->getOutput();
            usleep(500_000);
        }

        throw new PostmacloneException(
            "Docker database accepted healthchecks but not queries yet: {$lastError}"
        );
    }
}
