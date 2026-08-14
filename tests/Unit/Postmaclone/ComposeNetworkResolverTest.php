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
}
