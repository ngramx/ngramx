<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Config\LockFile;
use Ngramx\Config\LockFileData;
use Ngramx\Postmaclone\PostmacloneLockData;
use Ngramx\Postmaclone\PostmacloneService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class PostmacloneServiceComposeProjectTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pm-compose-project-' . uniqid('', true);
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        $lock = $this->dir . '/.ngramx.lock';
        if (is_file($lock)) {
            @unlink($lock);
        }
        @rmdir($this->dir);
    }

    public function test_reads_namespace_from_ngramx_lock(): void
    {
        (new LockFile($this->dir))->write(new LockFileData(
            namespace: 'ngramx-worktree-cor-281',
            portOffset: 100,
            startedAt: '2026-08-14T12:00:00+00:00',
        ));

        self::assertSame('ngramx-worktree-cor-281', $this->composeProjectName($this->dir));
    }

    public function test_prefers_postmaclone_lock_compose_project_over_ngramx_lock(): void
    {
        (new LockFile($this->dir))->write(new LockFileData(
            namespace: 'ngramx-worktree-cor-281',
            portOffset: 100,
            startedAt: '2026-08-14T12:00:00+00:00',
        ));

        $lock = new PostmacloneLockData(
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
                'compose_project' => 'ngramx-agent-1',
            ],
        );

        self::assertSame('ngramx-agent-1', $this->composeProjectName($this->dir, $lock));
    }

    public function test_returns_null_when_default_mode_has_no_namespace(): void
    {
        self::assertNull($this->composeProjectName($this->dir));
    }

    private function composeProjectName(string $projectRoot, ?PostmacloneLockData $lock = null): ?string
    {
        $method = new ReflectionMethod(PostmacloneService::class, 'composeProjectName');
        $method->setAccessible(true);

        return $method->invoke(new PostmacloneService(), $projectRoot, $lock);
    }
}
