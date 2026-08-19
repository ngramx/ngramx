<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Docker;

use Ngramx\Docker\BuildFingerprint;
use PHPUnit\Framework\TestCase;

class BuildFingerprintTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ngramx-build-fingerprint-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    public function test_it_hashes_the_dockerfile_contents(): void
    {
        $path = $this->tmpDir . '/Dockerfile';
        file_put_contents($path, "FROM php:8.4-fpm\nWORKDIR /var/www/html\n");

        $fingerprint = new BuildFingerprint();

        $this->assertSame(
            hash('sha256', "FROM php:8.4-fpm\nWORKDIR /var/www/html\n"),
            $fingerprint->dockerfileSha($path)
        );
    }

    public function test_the_hash_changes_when_the_base_image_changes(): void
    {
        $path = $this->tmpDir . '/Dockerfile';
        $fingerprint = new BuildFingerprint();

        file_put_contents($path, "FROM php:8.3-fpm\nWORKDIR /var/www/html\n");
        $before = $fingerprint->dockerfileSha($path);

        file_put_contents($path, "FROM php:8.4-fpm\nWORKDIR /var/www/html\n");
        $after = $fingerprint->dockerfileSha($path);

        $this->assertNotSame($before, $after);
    }

    public function test_it_ignores_line_ending_differences(): void
    {
        $lf = $this->tmpDir . '/Dockerfile.lf';
        $crlf = $this->tmpDir . '/Dockerfile.crlf';
        file_put_contents($lf, "FROM php:8.4-fpm\nWORKDIR /app\n");
        file_put_contents($crlf, "FROM php:8.4-fpm\r\nWORKDIR /app\r\n");

        $fingerprint = new BuildFingerprint();

        $this->assertSame($fingerprint->dockerfileSha($lf), $fingerprint->dockerfileSha($crlf));
    }

    public function test_it_returns_null_for_an_unreadable_dockerfile(): void
    {
        $fingerprint = new BuildFingerprint();

        $this->assertNull($fingerprint->dockerfileSha($this->tmpDir . '/nope'));
        $this->assertSame([], $fingerprint->labelsFor($this->tmpDir . '/nope'));
    }

    public function test_it_extracts_from_references(): void
    {
        $path = $this->tmpDir . '/Dockerfile';
        file_put_contents($path, "FROM node:22 AS assets\nRUN npm ci\n\nFROM php:8.4-fpm\nWORKDIR /app\n");

        $fingerprint = new BuildFingerprint();

        $this->assertSame(['node:22', 'php:8.4-fpm'], $fingerprint->fromReferences($path));
    }

    public function test_it_extracts_from_references_with_platform_flags(): void
    {
        $path = $this->tmpDir . '/Dockerfile';
        file_put_contents($path, "FROM --platform=linux/amd64 php:8.4-fpm\n");

        $fingerprint = new BuildFingerprint();

        $this->assertSame(['php:8.4-fpm'], $fingerprint->fromReferences($path));
    }

    public function test_it_builds_labels_for_a_dockerfile(): void
    {
        $path = $this->tmpDir . '/Dockerfile';
        file_put_contents($path, "FROM php:8.4-fpm\n");

        $labels = (new BuildFingerprint())->labelsFor($path);

        $this->assertSame(
            hash('sha256', "FROM php:8.4-fpm\n"),
            $labels[BuildFingerprint::LABEL_DOCKERFILE_SHA]
        );
        $this->assertSame('php:8.4-fpm', $labels[BuildFingerprint::LABEL_FROM]);
    }

    public function test_it_resolves_the_dockerfile_for_a_service(): void
    {
        file_put_contents($this->tmpDir . '/Dockerfile', "FROM php:8.4-fpm\n");
        $composeFile = $this->tmpDir . '/docker-compose.yml';

        $fingerprint = new BuildFingerprint();

        $this->assertSame(
            $this->tmpDir . '/Dockerfile',
            $fingerprint->dockerfilePathFor($composeFile, ['build' => ['context' => '.']])
        );
    }

    public function test_it_resolves_the_dockerfile_for_the_short_form_build(): void
    {
        file_put_contents($this->tmpDir . '/Dockerfile', "FROM php:8.4-fpm\n");
        $composeFile = $this->tmpDir . '/docker-compose.yml';

        $fingerprint = new BuildFingerprint();

        $this->assertSame(
            $this->tmpDir . '/Dockerfile',
            $fingerprint->dockerfilePathFor($composeFile, ['build' => '.'])
        );
    }

    public function test_it_honours_a_custom_dockerfile_name(): void
    {
        mkdir($this->tmpDir . '/docker');
        file_put_contents($this->tmpDir . '/docker/app.Dockerfile', "FROM php:8.4-fpm\n");
        $composeFile = $this->tmpDir . '/docker-compose.yml';

        $fingerprint = new BuildFingerprint();

        $this->assertSame(
            $this->tmpDir . '/docker/app.Dockerfile',
            $fingerprint->dockerfilePathFor($composeFile, [
                'build' => ['context' => '.', 'dockerfile' => 'docker/app.Dockerfile'],
            ])
        );
    }

    public function test_it_returns_null_for_a_service_without_a_build_section(): void
    {
        $composeFile = $this->tmpDir . '/docker-compose.yml';

        $fingerprint = new BuildFingerprint();

        $this->assertNull($fingerprint->dockerfilePathFor($composeFile, ['image' => 'redis:alpine']));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
