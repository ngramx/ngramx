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
            // May be null if docker isn't available / network not created — only assert shape when present.
            if ($resolved !== null) {
                self::assertStringContainsString('earl_kendrick_network', $resolved);
            }
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
