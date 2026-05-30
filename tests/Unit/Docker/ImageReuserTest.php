<?php

declare(strict_types=1);

namespace Cortex\Tests\Unit\Docker;

use Cortex\Docker\ImageReuser;
use PHPUnit\Framework\TestCase;

class ImageReuserTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cortex-imagereuser-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir . '/*') ?: []);
        @rmdir($this->tmpDir);
    }

    public function test_it_returns_services_built_from_source_without_explicit_image(): void
    {
        $compose = $this->tmpDir . '/docker-compose.yml';
        file_put_contents($compose, <<<'YAML'
        services:
          app:
            build:
              context: .
          db:
            image: postgres:16
          worker:
            build: .
            image: myorg/worker:latest
        YAML);

        $reuser = new ImageReuser();

        $this->assertSame(['app'], $reuser->builtServiceNames($compose));
    }

    public function test_it_returns_empty_for_missing_file(): void
    {
        $reuser = new ImageReuser();

        $this->assertSame([], $reuser->builtServiceNames($this->tmpDir . '/nope.yml'));
    }

    public function test_it_returns_empty_when_no_services(): void
    {
        $compose = $this->tmpDir . '/docker-compose.yml';
        file_put_contents($compose, "volumes:\n  data:\n");

        $reuser = new ImageReuser();

        $this->assertSame([], $reuser->builtServiceNames($compose));
    }
}
