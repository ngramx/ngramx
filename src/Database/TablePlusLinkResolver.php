<?php

declare(strict_types=1);

namespace Ngramx\Database;

use Ngramx\Config\DotEnvFileReader;
use Ngramx\Docker\PortMapping;
use Ngramx\Postmaclone\Connect\PostmacloneConnectLock;
use Ngramx\Postmaclone\EngineDetector;
use Symfony\Component\Yaml\Yaml;

/**
 * Build the TablePlus URL for the database the environment is actually using.
 *
 * A postmaclone connect lock wins: that session's database is the tunnel (or
 * the direct host), not the compose service. Otherwise the link uses the
 * app's .env credentials and the host port Docker published for the database
 * service, including a worktree port offset.
 */
final class TablePlusLinkResolver
{
    public function __construct(
        private readonly DotEnvFileReader $envFiles = new DotEnvFileReader(),
        private readonly EngineDetector $engines = new EngineDetector(),
    ) {
    }

    /**
     * @param array<int, int> $portMap
     */
    public function resolve(
        string $projectRoot,
        string $composeFile,
        int $portOffset = 0,
        array $portMap = [],
        bool $publishPorts = true,
    ): TablePlusLink {
        $root = rtrim($projectRoot, '/');
        $name = basename($root);

        $connected = $this->fromConnectLock($root, $name);
        if ($connected !== null) {
            return $connected;
        }

        if (!$publishPorts) {
            return new TablePlusLink();
        }

        $env = $this->envFiles->read($root . '/.env') ?? [];
        $composePath = $this->absolute($root, $composeFile);
        $services = $this->services($composePath);
        $endpoint = $this->endpoint($services, $env, $portOffset, $portMap);
        if ($endpoint === null) {
            return new TablePlusLink();
        }

        $credentials = $this->credentials($env, $endpoint['service']);
        if ($credentials === null) {
            return new TablePlusLink();
        }

        return new TablePlusLink(TablePlusUrl::build(
            engine: $endpoint['engine'],
            username: $credentials['username'],
            password: $credentials['password'],
            host: '127.0.0.1',
            port: $endpoint['port'],
            database: $credentials['database'],
            name: $name,
        ));
    }

    private function fromConnectLock(string $projectRoot, string $name): ?TablePlusLink
    {
        $lock = (new PostmacloneConnectLock($projectRoot))->read();
        if ($lock === null) {
            return null;
        }

        $url = TablePlusUrl::build(
            engine: $lock->engine,
            username: $lock->username,
            password: $lock->password,
            host: $lock->ideHost !== '' ? $lock->ideHost : '127.0.0.1',
            port: $lock->idePort,
            database: $lock->database,
            name: $name,
        );

        return $url === null ? new TablePlusLink() : new TablePlusLink($url);
    }

    /**
     * @param array<string, mixed> $services
     * @param array<string, string> $env
     * @param array<int, int> $portMap
     * @return array{engine: string, port: int, service: array<string, mixed>}|null
     */
    private function endpoint(array $services, array $env, int $portOffset, array $portMap): ?array
    {
        $wanted = $this->engineFromConnection($env['DB_CONNECTION'] ?? null);
        $containerPort = $this->containerPort($env, $wanted);

        $fallback = null;
        foreach ($services as $service) {
            if (!is_array($service)) {
                continue;
            }

            $engine = $this->engines->engineFromImage((string) ($service['image'] ?? ''));
            if ($engine === null) {
                continue;
            }

            $published = $this->publishedPort($service, $containerPort, $env, $portOffset, $portMap);
            if ($published === null) {
                continue;
            }

            $candidate = ['engine' => $engine, 'port' => $published, 'service' => $service];
            if ($wanted === null || $engine === $wanted) {
                return $candidate;
            }

            $fallback ??= $candidate;
        }

        return $fallback;
    }

    /**
     * @param array<string, mixed> $service
     * @param array<string, string> $env
     * @param array<int, int> $portMap
     */
    private function publishedPort(array $service, ?int $containerPort, array $env, int $portOffset, array $portMap): ?int
    {
        $ports = $service['ports'] ?? null;
        if (!is_array($ports)) {
            return null;
        }

        $fallback = null;
        foreach ($ports as $mapping) {
            $internal = $this->internalPort($mapping);
            $segment = $this->hostSegment($mapping);
            if ($segment === null) {
                continue;
            }

            $published = $this->hostPort($segment, $env, $portOffset, $portMap);
            if ($published === null) {
                continue;
            }

            if ($containerPort !== null && $internal === $containerPort) {
                return $published;
            }

            $fallback ??= $published;
        }

        return $fallback;
    }

    /**
     * A ${VAR} host port uses the variable when .env sets it. Compose does the
     * same, and a worktree offset only rewrites the default inside ${VAR:-5432},
     * which Docker ignores once VAR is set. A plain port is offset, or replaced
     * when startup recorded a per-port remap.
     *
     * @param array<string, string> $env
     * @param array<int, int> $portMap
     */
    private function hostPort(string $segment, array $env, int $portOffset, array $portMap): ?int
    {
        if (preg_match('/^\$\{([A-Za-z_][A-Za-z0-9_]*)(?::?-\d+)?\}$/', $segment, $matches) === 1) {
            $value = $env[$matches[1]] ?? '';
            if ($value !== '' && preg_match('/^\d+$/', $value) === 1) {
                return (int) $value;
            }
        }

        $base = PortMapping::hostPortNumber($segment);
        if ($base === null) {
            return null;
        }

        if (isset($portMap[$base])) {
            return $portMap[$base];
        }

        return $portOffset > 0 ? $base + $portOffset : $base;
    }

    private function internalPort(mixed $mapping): ?int
    {
        if (is_string($mapping)) {
            $parts = PortMapping::split($mapping);
            $container = $parts[count($parts) - 1] ?? null;
            if ($container === null) {
                return null;
            }

            return PortMapping::hostPortNumber(explode('/', $container)[0]);
        }

        if (is_array($mapping) && isset($mapping['target'])) {
            return (int) $mapping['target'];
        }

        return null;
    }

    private function hostSegment(mixed $mapping): ?string
    {
        if (is_string($mapping)) {
            $parts = PortMapping::split($mapping);
            if (count($parts) === 2) {
                return $parts[0];
            }
            if (count($parts) === 3) {
                return $parts[1];
            }

            return null;
        }

        if (is_array($mapping) && isset($mapping['published'])) {
            return (string) $mapping['published'];
        }

        return null;
    }

    /**
     * @param array<string, string> $env
     * @param array<string, mixed> $service
     * @return array{username: string, password: string, database: string}|null
     */
    private function credentials(array $env, array $service): ?array
    {
        $fromUrl = $this->fromDatabaseUrl($env['DATABASE_URL'] ?? null);
        $urlDatabase = $fromUrl['database'] ?? null;
        $urlUsername = $fromUrl['username'] ?? null;
        $urlPassword = $fromUrl['password'] ?? null;

        $database = $this->first($env, ['DB_DATABASE', 'DB_NAME'])
            ?? $urlDatabase
            ?? $this->composeValue($service, ['POSTGRES_DB', 'MYSQL_DATABASE']);
        $username = $this->first($env, ['DB_USERNAME', 'DB_USER'])
            ?? $urlUsername
            ?? $this->composeValue($service, ['POSTGRES_USER', 'MYSQL_USER']);
        if (array_key_exists('DB_PASSWORD', $env)) {
            $password = $env['DB_PASSWORD'];
        } else {
            $password = $urlPassword
                ?? $this->composeValue($service, ['POSTGRES_PASSWORD', 'MYSQL_PASSWORD', 'MYSQL_ROOT_PASSWORD'])
                ?? '';
        }

        if ($username === null || $username === '' || $database === null || $database === '') {
            return null;
        }

        return [
            'username' => $username,
            'password' => $password,
            'database' => $database,
        ];
    }

    /**
     * @return array{username: string, password: string, database: string}|null
     */
    private function fromDatabaseUrl(?string $url): ?array
    {
        if ($url === null || $url === '') {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }

        $database = isset($parts['path']) ? ltrim((string) $parts['path'], '/') : '';
        $username = isset($parts['user']) ? rawurldecode((string) $parts['user']) : '';
        if ($database === '' || $username === '') {
            return null;
        }

        return [
            'username' => $username,
            'password' => isset($parts['pass']) ? rawurldecode((string) $parts['pass']) : '',
            'database' => $database,
        ];
    }

    /**
     * @param array<string, string> $env
     * @param list<string> $keys
     */
    private function first(array $env, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $env[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $service
     * @param list<string> $keys
     */
    private function composeValue(array $service, array $keys): ?string
    {
        $environment = $service['environment'] ?? null;
        if (!is_array($environment)) {
            return null;
        }

        $values = [];
        foreach ($environment as $key => $value) {
            if (is_string($key) && (is_string($value) || is_int($value))) {
                $values[$key] = (string) $value;
                continue;
            }

            if (is_string($value) && str_contains($value, '=')) {
                [$name, $literal] = explode('=', $value, 2);
                $values[$name] = $literal;
            }
        }

        foreach ($keys as $key) {
            $value = $values[$key] ?? null;
            if ($value !== null && $value !== '' && !str_contains($value, '${')) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $env
     */
    private function containerPort(array $env, ?string $engine): ?int
    {
        $configured = $env['DB_PORT'] ?? '';
        if ($configured !== '' && preg_match('/^\d+$/', $configured) === 1) {
            return (int) $configured;
        }

        return match ($engine) {
            'mysql', 'mariadb' => 3306,
            'postgres' => 5432,
            default => null,
        };
    }

    private function engineFromConnection(?string $connection): ?string
    {
        return match (strtolower(trim((string) $connection))) {
            'pgsql', 'postgres', 'postgresql' => 'postgres',
            'mysql' => 'mysql',
            'mariadb' => 'mariadb',
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function services(string $composeFile): array
    {
        if ($composeFile === '' || !is_file($composeFile)) {
            return [];
        }

        try {
            $parsed = Yaml::parseFile($composeFile);
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($parsed) || !isset($parsed['services']) || !is_array($parsed['services'])) {
            return [];
        }

        return $parsed['services'];
    }

    private function absolute(string $projectRoot, string $path): string
    {
        if ($path === '' || str_starts_with($path, '/')) {
            return $path;
        }

        return $projectRoot . '/' . $path;
    }
}
