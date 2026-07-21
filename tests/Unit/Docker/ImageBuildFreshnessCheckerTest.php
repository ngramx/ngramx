<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Docker;

use Ngramx\Docker\ImageBuildFreshnessChecker;
use Ngramx\Docker\ComposeFiles;
use Ngramx\Docker\StaleBuildFinding;
use PHPUnit\Framework\TestCase;

class ImageBuildFreshnessCheckerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ngramx-freshness-checker-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    public function test_it_compares_host_and_image_contents_by_hash(): void
    {
        $path = $this->tmpDir . '/entrypoint.sh';
        file_put_contents($path, "#!/bin/bash\necho current\n");

        $checker = new ImageBuildFreshnessChecker();

        $this->assertTrue($checker->contentsMatch($path, "#!/bin/bash\necho current\n"));
        $this->assertFalse($checker->contentsMatch($path, "#!/bin/bash\necho old\n"));
    }

    public function test_it_formats_an_advisory_message(): void
    {
        $checker = new ImageBuildFreshnessChecker();
        $message = $checker->formatAdvisory([
            new StaleBuildFinding(
                service: 'app',
                image: 'project-app',
                reason: StaleBuildFinding::REASON_COMPOSE_NEWER_THAN_IMAGE,
                hostPath: '/repo/docker-compose.yml',
                composeInputPaths: ['docker-compose.yml', ComposeFiles::OVERRIDE_FILE],
            ),
        ]);

        $this->assertStringContainsString('compose stack was last changed', $message);
        $this->assertStringContainsString('docker-compose.yml', $message);
        $this->assertStringContainsString(ComposeFiles::OVERRIDE_FILE, $message);
        $this->assertStringContainsString('ngramx rebuild', $message);
    }

    public function test_it_formats_an_entrypoint_advisory_message(): void
    {
        $checker = new ImageBuildFreshnessChecker();
        $message = $checker->formatAdvisory([
            new StaleBuildFinding(
                service: 'app',
                image: 'project-app',
                reason: StaleBuildFinding::REASON_ENTRYPOINT_CHANGED,
                hostPath: '/repo/docker/entrypoint.sh',
                imagePath: '/usr/local/bin/entrypoint.sh',
            ),
        ]);

        $this->assertStringContainsString('Docker image is out of date', $message);
        $this->assertStringContainsString('docker/entrypoint.sh', $message);
        $this->assertStringContainsString('ngramx rebuild', $message);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
