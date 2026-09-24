<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Postmaclone\Exception\PostmacloneException;
use Ngramx\Postmaclone\Restore\MysqlRestorer;
use Ngramx\Postmaclone\Restore\MysqlRunner;
use Ngramx\Postmaclone\Target\EphemeralTarget;
use PHPUnit\Framework\TestCase;

final class MysqlRestorerTest extends TestCase
{
    public function test_restore_uses_speed_flags_and_no_process_timeout(): void
    {
        $dump = $this->writeDump("-- hydra dump\nINSERT INTO t VALUES (1);\n");
        $seenArgs = null;
        $seenTimeout = 'unset';

        $runner = $this->createMock(MysqlRunner::class);
        $runner->expects($this->once())
            ->method('run')
            ->willReturnCallback(function ($target, array $args, $stdin, $timeout) use (&$seenArgs, &$seenTimeout): void {
                $seenArgs = $args;
                $seenTimeout = $timeout;
                self::assertIsResource($stdin);
                stream_get_contents($stdin);
            });

        (new MysqlRestorer($runner))->restore($dump, $this->target());

        self::assertSame(MysqlRestorer::RESTORE_CLIENT_ARGS, $seenArgs);
        self::assertNull($seenTimeout);
        @unlink($dump);
    }

    public function test_restore_reports_byte_progress(): void
    {
        $dump = $this->writeDump(str_repeat("INSERT INTO t VALUES (1);\n", 400));
        $messages = [];

        $runner = $this->createMock(MysqlRunner::class);
        $runner->method('run')->willReturnCallback(function ($target, array $args, $stdin): void {
            stream_get_contents($stdin);
        });

        $restorer = new MysqlRestorer(
            $runner,
            onProgress: static function (string $message) use (&$messages): void {
                $messages[] = $message;
            },
        );
        $restorer->restore($dump, $this->target());

        self::assertContains('Loading dump into scratch (this can take several hours)', $messages);
        self::assertContains('Loading dump into scratch 100%', $messages);
        self::assertContains('Restore finished', $messages);
        @unlink($dump);
    }

    public function test_restore_does_not_report_100_percent_when_stream_closes_early(): void
    {
        $dump = $this->writeDump(str_repeat("INSERT INTO t VALUES (1);\n", 400));
        $messages = [];

        $runner = $this->createMock(MysqlRunner::class);
        $runner->method('run')->willReturnCallback(function ($target, array $args, $stdin): void {
            fread($stdin, 100);
            throw new PostmacloneException('connection lost');
        });

        $restorer = new MysqlRestorer(
            $runner,
            onProgress: static function (string $message) use (&$messages): void {
                $messages[] = $message;
            },
        );

        try {
            $restorer->restore($dump, $this->target());
            self::fail('Expected restore to fail');
        } catch (PostmacloneException $e) {
            self::assertStringContainsString('mysql restore failed', $e->getMessage());
        }

        self::assertNotContains('Loading dump into scratch 100%', $messages);
        self::assertNotContains('Restore finished', $messages);
        @unlink($dump);
    }

    private function writeDump(string $sql): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ngramx-mysql-restore-');
        self::assertNotFalse($path);
        file_put_contents($path, $sql);

        return $path;
    }

    private function target(): EphemeralTarget
    {
        return new EphemeralTarget(
            provider: 'remote',
            engine: 'mysql',
            host: 'db.example.com',
            port: 25060,
            database: 'hydra_prod_scratch',
            username: 'postmaclone_scratch',
            password: 'secret',
            databaseUrl: 'mysql://postmaclone_scratch:secret@db.example.com:25060/hydra_prod_scratch',
            expiresAt: '2026-09-22T16:00:00+00:00',
        );
    }
}
