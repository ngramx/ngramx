<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Config\Schema\DockerConfig;
use Ngramx\Config\Schema\N8nConfig;
use Ngramx\Config\Schema\NgramxConfig;
use Ngramx\Config\Schema\Postmaclone\PostmacloneConfig;
use Ngramx\Config\Schema\Postmaclone\TargetConfig;
use Ngramx\Config\Schema\SetupConfig;
use Ngramx\Postmaclone\PostmacloneService;
use Ngramx\Postmaclone\Target\DockerDbTarget;
use Ngramx\Postmaclone\Target\EphemeralTargetInterface;
use Ngramx\Postmaclone\Target\NeonTarget;
use Ngramx\Postmaclone\Target\RemoteDbTarget;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class PostmacloneServiceBuildTargetTest extends TestCase
{
    private mixed $previousNeonKey;
    private mixed $previousRemoteUrl;

    protected function setUp(): void
    {
        $this->previousNeonKey = getenv(NeonTarget::API_KEY_ENV);
        $this->previousRemoteUrl = getenv('POSTMACLONE_REMOTE_URL');
        putenv(NeonTarget::API_KEY_ENV);
        putenv('POSTMACLONE_REMOTE_URL');
    }

    protected function tearDown(): void
    {
        $this->restoreEnv(NeonTarget::API_KEY_ENV, $this->previousNeonKey);
        $this->restoreEnv('POSTMACLONE_REMOTE_URL', $this->previousRemoteUrl);
    }

    public function test_auto_postgres_falls_back_to_docker_without_neon_api_key(): void
    {
        $target = $this->buildTarget($this->config(), PostmacloneConfig::ENGINE_POSTGRES);

        self::assertInstanceOf(DockerDbTarget::class, $target);
    }

    public function test_auto_postgres_uses_neon_when_api_key_is_set(): void
    {
        putenv(NeonTarget::API_KEY_ENV . '=test-neon-key');

        $target = $this->buildTarget($this->config(), PostmacloneConfig::ENGINE_POSTGRES);

        self::assertInstanceOf(NeonTarget::class, $target);
    }

    public function test_auto_mysql_uses_docker_even_when_neon_api_key_is_set(): void
    {
        putenv(NeonTarget::API_KEY_ENV . '=test-neon-key');

        $target = $this->buildTarget($this->config(), PostmacloneConfig::ENGINE_MYSQL);

        self::assertInstanceOf(DockerDbTarget::class, $target);
    }

    public function test_auto_prefers_remote_for_large_artifacts_when_url_is_configured(): void
    {
        $config = $this->config(new TargetConfig(
            provider: TargetConfig::PROVIDER_AUTO,
            remoteUrl: 'postgres://clone:secret@127.0.0.1:5432/postmaclone',
        ));

        $target = $this->buildTarget(
            $config,
            PostmacloneConfig::ENGINE_POSTGRES,
            TargetConfig::DEFAULT_REMOTE_THRESHOLD_BYTES,
        );

        self::assertInstanceOf(RemoteDbTarget::class, $target);
    }

    public function test_auto_large_postgres_without_remote_or_neon_falls_back_to_docker(): void
    {
        $target = $this->buildTarget(
            $this->config(),
            PostmacloneConfig::ENGINE_POSTGRES,
            TargetConfig::DEFAULT_REMOTE_THRESHOLD_BYTES,
        );

        self::assertInstanceOf(DockerDbTarget::class, $target);
    }

    public function test_explicit_neon_provider_is_not_rewritten_without_api_key(): void
    {
        $config = $this->config(new TargetConfig(provider: TargetConfig::PROVIDER_NEON));

        $target = $this->buildTarget($config, PostmacloneConfig::ENGINE_POSTGRES);

        self::assertInstanceOf(NeonTarget::class, $target);
    }

    private function buildTarget(
        NgramxConfig $config,
        string $engine,
        ?int $artifactSizeBytes = null,
    ): EphemeralTargetInterface {
        $method = new ReflectionMethod(PostmacloneService::class, 'buildTarget');
        $method->setAccessible(true);

        return $method->invoke(
            new PostmacloneService(),
            $config,
            $config->postmaclone,
            $engine,
            sys_get_temp_dir(),
            $artifactSizeBytes,
        );
    }

    private function config(?TargetConfig $target = null): NgramxConfig
    {
        return new NgramxConfig(
            version: '1',
            docker: new DockerConfig('docker-compose.yml', 'app', 'http://localhost'),
            setup: new SetupConfig(),
            n8n: new N8nConfig('./.n8n'),
            postmaclone: new PostmacloneConfig(
                engine: PostmacloneConfig::ENGINE_POSTGRES,
                target: $target ?? new TargetConfig(provider: TargetConfig::PROVIDER_AUTO),
            ),
        );
    }

    private function restoreEnv(string $name, mixed $previous): void
    {
        if (is_string($previous) && $previous !== '') {
            putenv($name . '=' . $previous);

            return;
        }

        putenv($name);
    }
}
