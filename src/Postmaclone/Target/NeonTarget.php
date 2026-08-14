<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Target;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Ngramx\Postmaclone\Connection\ConnectionFactory;
use Ngramx\Postmaclone\Exception\PostmacloneException;
use Ngramx\Postmaclone\PostmacloneLockData;

class NeonTarget implements EphemeralTargetInterface
{
    public const API_KEY_ENV = 'NEON_API_KEY';

    private const API = 'https://console.neon.tech/api/v2';

    public function __construct(
        private readonly ?string $projectId = null,
        private readonly ?string $regionId = null,
        private readonly ?Client $client = null,
        private readonly ConnectionFactory $connections = new ConnectionFactory(),
    ) {
    }

    public function provision(string $engine, int $ttlHours): EphemeralTarget
    {
        if ($engine !== 'postgres') {
            throw new PostmacloneException('Neon target only supports postgres engine');
        }

        $apiKey = self::apiKey();
        if ($apiKey === '') {
            throw new PostmacloneException(self::API_KEY_ENV . ' is required for the Neon target');
        }

        $client = $this->client ?? new Client([
            'base_uri' => self::API . '/',
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);

        $ttlHours = min(max(1, $ttlHours), 24 * 30);
        $expiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify("+{$ttlHours} hours")
            ->format('c');

        $createdProject = false;
        $projectId = $this->projectId;

        if ($projectId === null || $projectId === '') {
            $projectId = $this->createProject($client);
            $createdProject = true;
        }

        $branchName = 'postmaclone-' . substr(bin2hex(random_bytes(6)), 0, 10);
        $password = bin2hex(random_bytes(16));

        try {
            $body = [
                'branch' => [
                    'name' => $branchName,
                    'expires_at' => $expiresAt,
                ],
                'endpoints' => [
                    ['type' => 'read_write'],
                ],
            ];

            $response = $client->post("projects/{$projectId}/branches", ['json' => $body]);
            $payload = json_decode((string) $response->getBody(), true);
            if (!is_array($payload)) {
                throw new PostmacloneException('Invalid Neon create branch response');
            }

            $branchId = (string) ($payload['branch']['id'] ?? '');
            $endpoint = $payload['endpoints'][0] ?? null;
            $host = is_array($endpoint) ? (string) ($endpoint['host'] ?? '') : '';
            $endpointId = is_array($endpoint) ? (string) ($endpoint['id'] ?? '') : '';

            if ($branchId === '' || $host === '') {
                throw new PostmacloneException('Neon branch response missing host/branch id');
            }

            // Create a dedicated role with a known password for this clone.
            $roleName = 'postmaclone_' . substr(bin2hex(random_bytes(4)), 0, 8);
            $roleResponse = $client->post("projects/{$projectId}/branches/{$branchId}/roles", [
                'json' => ['role' => ['name' => $roleName]],
            ]);
            $rolePayload = json_decode((string) $roleResponse->getBody(), true);
            $rolePassword = is_array($rolePayload)
                ? (string) ($rolePayload['role']['password'] ?? $password)
                : $password;

            // Ensure a database exists
            $dbName = 'postmaclone';
            try {
                $client->post("projects/{$projectId}/branches/{$branchId}/databases", [
                    'json' => [
                        'database' => [
                            'name' => $dbName,
                            'owner_name' => $roleName,
                        ],
                    ],
                ]);
            } catch (GuzzleException) {
                $dbName = 'neondb';
            }

            $this->waitEndpoint($client, $projectId, $endpointId);

            $url = $this->connections->buildUrl('postgres', $host, 5432, $dbName, $roleName, $rolePassword);

            return new EphemeralTarget(
                provider: 'neon',
                engine: 'postgres',
                host: $host,
                port: 5432,
                database: $dbName,
                username: $roleName,
                password: $rolePassword,
                databaseUrl: $url,
                expiresAt: $expiresAt,
                meta: [
                    'project_id' => $projectId,
                    'branch_id' => $branchId,
                    'endpoint_id' => $endpointId,
                    'created_project' => $createdProject,
                ],
            );
        } catch (GuzzleException $e) {
            throw new PostmacloneException('Neon API error: ' . $e->getMessage(), 0, $e);
        }
    }

    public function destroy(PostmacloneLockData $lock): void
    {
        $apiKey = self::apiKey();
        if ($apiKey === '') {
            throw new PostmacloneException(self::API_KEY_ENV . ' is required to destroy a Neon clone');
        }

        $client = $this->client ?? new Client([
            'base_uri' => self::API . '/',
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept' => 'application/json',
            ],
        ]);

        $projectId = (string) ($lock->providerMeta['project_id'] ?? '');
        $branchId = (string) ($lock->providerMeta['branch_id'] ?? '');
        $createdProject = (bool) ($lock->providerMeta['created_project'] ?? false);

        try {
            if ($createdProject && $projectId !== '') {
                $client->delete("projects/{$projectId}");
            } elseif ($projectId !== '' && $branchId !== '') {
                $client->delete("projects/{$projectId}/branches/{$branchId}");
            }
        } catch (GuzzleException $e) {
            throw new PostmacloneException('Failed to destroy Neon resources: ' . $e->getMessage(), 0, $e);
        }
    }

    public static function hasApiKey(): bool
    {
        return self::apiKey() !== '';
    }

    private static function apiKey(): string
    {
        return getenv(self::API_KEY_ENV) ?: '';
    }

    private function createProject(Client $client): string
    {
        $body = [
            'project' => [
                'name' => 'ngramx-postmaclone-' . substr(bin2hex(random_bytes(4)), 0, 8),
            ],
        ];
        if ($this->regionId) {
            $body['project']['region_id'] = $this->regionId;
        }

        try {
            $response = $client->post('projects', ['json' => $body]);
        } catch (GuzzleException $e) {
            throw new PostmacloneException('Failed to create Neon project: ' . $e->getMessage(), 0, $e);
        }

        $payload = json_decode((string) $response->getBody(), true);
        $id = is_array($payload) ? (string) ($payload['project']['id'] ?? '') : '';
        if ($id === '') {
            throw new PostmacloneException('Neon create project response missing id');
        }

        return $id;
    }

    private function waitEndpoint(Client $client, string $projectId, string $endpointId): void
    {
        if ($endpointId === '') {
            sleep(3);

            return;
        }

        $deadline = time() + 90;
        while (time() < $deadline) {
            try {
                $response = $client->get("projects/{$projectId}/endpoints/{$endpointId}");
                $payload = json_decode((string) $response->getBody(), true);
                $state = is_array($payload) ? (string) ($payload['endpoint']['current_state'] ?? '') : '';
                if (in_array($state, ['active', 'idle'], true)) {
                    return;
                }
            } catch (GuzzleException) {
                // retry
            }
            usleep(500_000);
        }
    }
}
