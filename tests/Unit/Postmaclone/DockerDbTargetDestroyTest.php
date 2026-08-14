<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Docker\DockerCompose;
use Ngramx\Postmaclone\Exception\PostmacloneException;
use Ngramx\Postmaclone\PostmacloneLockData;
use Ngramx\Postmaclone\Target\ComposeDbServiceSwitcher;
use Ngramx\Postmaclone\Target\ComposeNetworkResolver;
use Ngramx\Postmaclone\Target\DockerDbTarget;
use PHPUnit\Framework\TestCase;

final class DockerDbTargetDestroyTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pm-db-destroy-' . uniqid('', true);
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function test_failed_container_removal_still_restarts_compose_db(): void
    {
        $composeFile = $this->composeFile();
        $docker = $this->createMock(DockerCompose::class);
        $docker->expects($this->once())
            ->method('startService')
            ->with($composeFile, 'db', null);

        $removed = [];
        $target = new DockerDbTargetDestroyDouble(
            new ComposeDbServiceSwitcher($docker),
            $composeFile,
            function (string $name) use (&$removed): void {
                $removed[] = $name;
                throw new PostmacloneException('Failed to remove Docker DB container: daemon error');
            },
        );

        try {
            $target->destroy($this->lock($composeFile, 'ngramx-postmaclone-stale'));
            $this->fail('Expected PostmacloneException');
        } catch (PostmacloneException $e) {
            $this->assertSame('Failed to remove Docker DB container: daemon error', $e->getMessage());
        }

        $this->assertSame(['ngramx-postmaclone-stale'], $removed);
    }

    public function test_successful_container_removal_restarts_compose_db(): void
    {
        $composeFile = $this->composeFile();
        $docker = $this->createMock(DockerCompose::class);
        $docker->expects($this->once())
            ->method('startService')
            ->with($composeFile, 'db', null);

        $target = new DockerDbTargetDestroyDouble(
            new ComposeDbServiceSwitcher($docker),
            $composeFile,
            function (string $name): void {
                $this->assertSame('ngramx-postmaclone-abc', $name);
            },
        );

        $target->destroy($this->lock($composeFile, 'ngramx-postmaclone-abc'));
    }

    public function test_restart_uses_compose_project_from_lock_meta(): void
    {
        $composeFile = $this->composeFile();
        $docker = $this->createMock(DockerCompose::class);
        $docker->expects($this->once())
            ->method('startService')
            ->with($composeFile, 'db', 'ngramx-worktree-cor-281');

        $target = new DockerDbTargetDestroyDouble(
            new ComposeDbServiceSwitcher($docker),
            $composeFile,
            function (string $name): void {
                $this->assertSame('ngramx-postmaclone-abc', $name);
            },
        );

        $target->destroy($this->lock($composeFile, 'ngramx-postmaclone-abc', 'ngramx-worktree-cor-281'));
    }

    public function test_claim_compose_dns_stops_namespaced_project(): void
    {
        $composeFile = $this->composeFile();
        $networks = $this->createMock(ComposeNetworkResolver::class);
        $networks->expects($this->once())
            ->method('resolve')
            ->with($composeFile, 'app', 'ngramx-worktree-cor-281')
            ->willReturn('ngramx-worktree-cor-281_default');

        $docker = $this->createMock(DockerCompose::class);
        $docker->expects($this->once())
            ->method('stopService')
            ->with($composeFile, 'db', 'ngramx-worktree-cor-281');

        $target = new DockerDbTargetClaimDouble(
            composeFile: $composeFile,
            primaryService: 'app',
            projectName: 'ngramx-worktree-cor-281',
            networks: $networks,
            dbSwitcher: new ComposeDbServiceSwitcher($docker),
        );

        $claimed = $target->claim();
        $this->assertSame('ngramx-worktree-cor-281_default', $claimed['network']);
        $this->assertSame('db', $claimed['stoppedDbService']);
    }

    private function composeFile(): string
    {
        $path = $this->dir . '/docker-compose.yml';
        file_put_contents($path, "services:\n  db:\n    image: mysql:8\n");

        return $path;
    }

    private function lock(string $composeFile, string $container, ?string $composeProject = null): PostmacloneLockData
    {
        return new PostmacloneLockData(
            provider: 'docker',
            engine: 'mysql',
            createdAt: '2026-08-14T12:00:00+00:00',
            expiresAt: '2026-08-14T16:00:00+00:00',
            host: 'db',
            port: 3306,
            database: 'postmaclone',
            username: 'postmaclone',
            password: 'secret',
            databaseUrl: 'mysql://postmaclone:secret@db:3306/postmaclone',
            providerMeta: [
                'container_name' => $container,
                'container_id' => 'abc123',
                'stopped_db_service' => 'db',
                'compose_file' => $composeFile,
                'compose_project' => $composeProject,
            ],
        );
    }
}

/**
 * @internal
 */
final class DockerDbTargetDestroyDouble extends DockerDbTarget
{
    public function __construct(
        ComposeDbServiceSwitcher $dbSwitcher,
        string $composeFile,
        private readonly \Closure $onRemove,
    ) {
        parent::__construct(composeFile: $composeFile, dbSwitcher: $dbSwitcher);
    }

    protected function removeEphemeralContainer(string $target): void
    {
        ($this->onRemove)($target);
    }
}

/**
 * @internal
 */
final class DockerDbTargetClaimDouble extends DockerDbTarget
{
    /**
     * @return array{network: ?string, dbService: ?string, alias: string, stoppedDbService: ?string}
     */
    public function claim(): array
    {
        return $this->claimComposeDnsName();
    }
}
