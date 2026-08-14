<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Postmaclone\Target\ComposeDbServiceSwitcher;
use PHPUnit\Framework\TestCase;

final class ComposeDbServiceSwitcherTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pm-db-switch-' . uniqid('', true);
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testPrefersServiceNamedDb(): void
    {
        $compose = $this->dir . '/docker-compose.yml';
        file_put_contents($compose, <<<'YAML'
services:
  app:
    image: php
  db:
    image: postgres:17
  other:
    image: mysql:8
YAML);

        $switcher = new ComposeDbServiceSwitcher();
        self::assertSame('db', $switcher->detectServiceName($compose));
        self::assertSame('db', $switcher->networkAlias($compose));
    }

    public function testFallsBackToFirstDatabaseImage(): void
    {
        $compose = $this->dir . '/docker-compose.yml';
        file_put_contents($compose, <<<'YAML'
services:
  app:
    image: php
  database:
    image: postgres:17
YAML);

        self::assertSame('database', (new ComposeDbServiceSwitcher())->detectServiceName($compose));
        self::assertSame('database', (new ComposeDbServiceSwitcher())->networkAlias($compose));
    }

    public function testMissingComposeReturnsNullServiceAndDefaultAlias(): void
    {
        $switcher = new ComposeDbServiceSwitcher();
        self::assertNull($switcher->detectServiceName('/no/such/compose.yml'));
        self::assertSame('db', $switcher->networkAlias('/no/such/compose.yml'));
    }
}
