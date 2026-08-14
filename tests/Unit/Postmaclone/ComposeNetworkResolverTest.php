<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Postmaclone\Target\ComposeNetworkResolver;
use PHPUnit\Framework\TestCase;

final class ComposeNetworkResolverTest extends TestCase
{
    public function testResolvesFromComposeFileWhenNetworkExists(): void
    {
        $dir = sys_get_temp_dir() . '/pm-net-' . uniqid('', true);
        mkdir($dir, 0700, true);
        $compose = $dir . '/docker-compose.yml';
        file_put_contents($compose, <<<'YAML'
services:
  app:
    image: alpine
    networks: [earl_kendrick_network]
networks:
  earl_kendrick_network:
    driver: bridge
YAML);

        try {
            $resolved = (new ComposeNetworkResolver())->resolve($compose, 'app');
            // Null when docker isn't available / the compose network is not created yet.
            self::assertTrue(
                $resolved === null || str_contains($resolved, 'earl_kendrick_network'),
                'Expected null (no docker network) or a name containing earl_kendrick_network'
            );
        } finally {
            @unlink($compose);
            @rmdir($dir);
        }
    }

    public function testMissingComposeReturnsNull(): void
    {
        self::assertNull((new ComposeNetworkResolver())->resolve('/no/such/compose.yml', 'app'));
    }

    public function testRunningServicePsCommandIncludesProjectName(): void
    {
        $compose = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'docker-compose.yml';
        $resolver = new ComposeNetworkResolver();

        self::assertSame(
            ['docker', 'compose', '-f', $compose, 'ps', '-q', 'app'],
            $resolver->runningServicePsCommand($compose, 'app', null),
        );
        self::assertSame(
            ['docker', 'compose', '-f', $compose, 'ps', '-q', 'app'],
            $resolver->runningServicePsCommand($compose, 'app', ''),
        );
        self::assertSame(
            ['docker', 'compose', '-f', $compose, '-p', 'ngramx-worktree-cor-281', 'ps', '-q', 'app'],
            $resolver->runningServicePsCommand($compose, 'app', 'ngramx-worktree-cor-281'),
        );
    }

    public function testMatchExistingNetworkPrefersNamespacedProject(): void
    {
        $resolver = new ComposeNetworkResolver();
        $declared = ['default'];
        $existing = ['myapp_default', 'ngramx-worktree-cor-281_default'];

        self::assertSame(
            'ngramx-worktree-cor-281_default',
            $resolver->matchExistingNetwork($declared, $existing, 'ngramx-worktree-cor-281'),
        );
        self::assertSame(
            'myapp_default',
            $resolver->matchExistingNetwork($declared, $existing, null),
        );
    }

    public function testMatchExistingNetworkDoesNotFallBackToAnotherProjectWhenNamespacedMisses(): void
    {
        $resolver = new ComposeNetworkResolver();
        $declared = ['default'];
        $existing = ['myapp_default', 'other-stack_default'];

        self::assertNull(
            $resolver->matchExistingNetwork($declared, $existing, 'ngramx-worktree-cor-281'),
        );
        self::assertSame(
            'myapp_default',
            $resolver->matchExistingNetwork($declared, $existing, null),
        );
    }
}
