<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Config\Schema\Postmaclone\BackupCredentialsConfig;
use Ngramx\Postmaclone\Backup\OpSecretReader;
use Ngramx\Postmaclone\Backup\S3Credentials;
use Ngramx\Postmaclone\Exception\PostmacloneException;
use PHPUnit\Framework\TestCase;

final class S3CredentialsTest extends TestCase
{
    /** @var list<string> */
    private array $envKeys = [
        'POSTMACLONE_S3_KEY',
        'POSTMACLONE_S3_SECRET',
        'POSTMACLONE_S3_SESSION_TOKEN',
        'AWS_ACCESS_KEY_ID',
        'AWS_SECRET_ACCESS_KEY',
        'AWS_SESSION_TOKEN',
    ];

    protected function setUp(): void
    {
        foreach ($this->envKeys as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        $this->setUp();
    }

    public function testRequireUsesEnvironmentWhenNoConfigRefs(): void
    {
        putenv('POSTMACLONE_S3_KEY=pk');
        putenv('POSTMACLONE_S3_SECRET=ps');

        [$key, $secret, $token] = (new S3Credentials())->require();

        self::assertSame('pk', $key);
        self::assertSame('ps', $secret);
        self::assertNull($token);
    }

    public function testRequirePrefersConfigRefsOverEnvironment(): void
    {
        putenv('POSTMACLONE_S3_KEY=pk');
        putenv('POSTMACLONE_S3_SECRET=ps');

        $refs = new BackupCredentialsConfig(
            key: S3Credentials::EXAMPLE_KEY_REF,
            secret: S3Credentials::EXAMPLE_SECRET_REF,
        );
        $reader = $this->createMock(OpSecretReader::class);
        $reader->expects(self::exactly(2))
            ->method('read')
            ->willReturnCallback(static fn (string $ref): string => match ($ref) {
                S3Credentials::EXAMPLE_KEY_REF => 'from-op-key',
                S3Credentials::EXAMPLE_SECRET_REF => 'from-op-secret',
                default => self::fail('unexpected ref'),
            });

        [$key, $secret, $token] = (new S3Credentials($refs, $reader))->require();

        self::assertSame('from-op-key', $key);
        self::assertSame('from-op-secret', $secret);
        self::assertNull($token);
    }

    public function testRequireResolvesConfigRefsViaOp(): void
    {
        $refs = new BackupCredentialsConfig(
            key: S3Credentials::EXAMPLE_KEY_REF,
            secret: S3Credentials::EXAMPLE_SECRET_REF,
        );
        $reader = $this->createMock(OpSecretReader::class);
        $reader->expects(self::exactly(2))
            ->method('read')
            ->willReturnCallback(static fn (string $ref): string => match ($ref) {
                S3Credentials::EXAMPLE_KEY_REF => 'from-op-key',
                S3Credentials::EXAMPLE_SECRET_REF => 'from-op-secret',
                default => self::fail('unexpected ref'),
            });

        [$key, $secret] = (new S3Credentials($refs, $reader))->require();

        self::assertSame('from-op-key', $key);
        self::assertSame('from-op-secret', $secret);
    }

    public function testMissingCredentialsMessageMentionsYamlCredentials(): void
    {
        $message = S3Credentials::missingCredentialsMessage();

        self::assertStringContainsString('backup:', $message);
        self::assertStringContainsString('credentials:', $message);
        self::assertStringContainsString('ngramx-db-backup-read-access', $message);
        self::assertStringContainsString('OP_SERVICE_ACCOUNT_TOKEN', $message);
        if (S3Credentials::isOpAvailable()) {
            self::assertStringContainsString('ngramx postmaclone doctor', $message);
        } else {
            self::assertStringContainsString('Install 1Password CLI', $message);
        }
    }

    public function testRequireThrowsWhenMissing(): void
    {
        $this->expectException(PostmacloneException::class);
        $this->expectExceptionMessage('S3 credentials missing');

        (new S3Credentials())->require();
    }

    public function testDoctorChecksIncludeConfigRefs(): void
    {
        $refs = new BackupCredentialsConfig(
            key: S3Credentials::EXAMPLE_KEY_REF,
            secret: S3Credentials::EXAMPLE_SECRET_REF,
        );
        $checks = S3Credentials::doctorChecks($refs);
        $messages = array_column($checks, 'message');
        self::assertTrue(
            (bool) array_filter($messages, static fn (string $m): bool => str_contains($m, 'Credential refs in ngramx.yml'))
        );
    }
}
