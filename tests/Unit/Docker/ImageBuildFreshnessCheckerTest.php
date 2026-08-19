<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Docker;

use Ngramx\Docker\ImageBuildFreshnessChecker;
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

    public function test_it_normalizes_line_endings_when_comparing_scripts(): void
    {
        $path = $this->tmpDir . '/entrypoint.sh';
        file_put_contents($path, "#!/bin/bash\r\necho current\r\n");

        $checker = new ImageBuildFreshnessChecker();

        $this->assertTrue($checker->contentsMatch($path, "#!/bin/bash\necho current\n"));
    }

    public function test_it_formats_an_advisory_message(): void
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

    public function test_it_names_the_old_and_new_base_image_in_the_advisory(): void
    {
        $checker = new ImageBuildFreshnessChecker();
        $message = $checker->formatAdvisory([
            new StaleBuildFinding(
                service: 'app',
                image: 'terrablock-app',
                reason: StaleBuildFinding::REASON_BASE_IMAGE_CHANGED,
                hostPath: '/repo/Dockerfile',
                previousFrom: 'php:8.3-fpm',
                currentFrom: 'php:8.4-fpm',
            ),
        ]);

        $this->assertStringContainsString('php:8.3-fpm', $message);
        $this->assertStringContainsString('php:8.4-fpm', $message);
        $this->assertStringContainsString('ngramx rebuild', $message);
    }

    public function test_it_reports_a_changed_dockerfile(): void
    {
        $checker = new ImageBuildFreshnessChecker();
        $message = $checker->formatAdvisory([
            new StaleBuildFinding(
                service: 'app',
                image: 'terrablock-app',
                reason: StaleBuildFinding::REASON_DOCKERFILE_CHANGED,
                hostPath: '/repo/Dockerfile',
            ),
        ]);

        $this->assertStringContainsString('older `Dockerfile`', $message);
        $this->assertStringContainsString('ngramx rebuild', $message);
    }

    public function test_it_reports_no_findings_for_an_image_without_a_fingerprint(): void
    {
        // Images built before this check existed carry no fingerprint label.
        // They must stay silent rather than flagging every existing install.
        file_put_contents($this->tmpDir . '/Dockerfile', "FROM php:8.4-fpm\n");
        $composeFile = $this->tmpDir . '/docker-compose.yml';
        file_put_contents($composeFile, "services:\n  app:\n    build:\n      context: .\n");

        $checker = new ImageBuildFreshnessChecker();

        // No such image exists locally, so there is nothing to compare against
        // and nothing should be reported.
        $this->assertSame([], $checker->findStaleBuildInputs($composeFile, 'ngramx-nonexistent-project'));
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
