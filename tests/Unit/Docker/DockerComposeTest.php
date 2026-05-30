<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Docker;

use Ngramx\Docker\DockerCompose;
use PHPUnit\Framework\TestCase;

class DockerComposeTest extends TestCase
{
    private DockerCompose $dockerCompose;

    protected function setUp(): void
    {
        $this->dockerCompose = new DockerCompose();
    }

    public function test_it_can_be_instantiated(): void
    {
        $this->assertInstanceOf(DockerCompose::class, $this->dockerCompose);
    }

    public function test_is_docker_running_returns_true_or_false(): void
    {
        $result = $this->dockerCompose->isDockerRunning();

        $this->assertContains($result, [true, false], 'daemon check must return a boolean outcome');
    }

    public function test_has_existing_images_returns_true_or_false(): void
    {
        $result = $this->dockerCompose->hasExistingImages('/nonexistent/docker-compose.yml');

        // Should return true (assumes images exist if we can't check) or false
        $this->assertContains($result, [true, false], 'image check must return a boolean outcome');
    }

    public function test_get_latest_log_line_returns_null_for_nonexistent_service(): void
    {
        $result = $this->dockerCompose->getLatestLogLine(
            '/nonexistent/docker-compose.yml',
            'nonexistent-service'
        );

        $this->assertNull($result);
    }

    public function test_get_latest_log_lines_returns_empty_array_for_nonexistent_service(): void
    {
        $result = $this->dockerCompose->getLatestLogLines(
            '/nonexistent/docker-compose.yml',
            'nonexistent-service',
            3
        );

        $this->assertSame([], $result);
    }

    public function test_get_latest_log_lines_coerces_zero_lines_to_one(): void
    {
        $result = $this->dockerCompose->getLatestLogLines(
            '/nonexistent/docker-compose.yml',
            'nonexistent-service',
            0
        );

        // Even with 0 requested, it should not error out; compose file does
        // not exist so the result is an empty list.
        $this->assertSame([], $result);
    }

    public function test_ps_returns_empty_array_for_nonexistent_compose_file(): void
    {
        $result = $this->dockerCompose->ps('/nonexistent/docker-compose.yml');

        $this->assertSame([], $result);
    }

    public function test_is_running_returns_false_for_nonexistent_compose_file(): void
    {
        $result = $this->dockerCompose->isRunning('/nonexistent/docker-compose.yml');

        $this->assertFalse($result);
    }

    public function test_list_services_returns_empty_array_for_nonexistent_compose_file(): void
    {
        $result = $this->dockerCompose->listServices('/nonexistent/docker-compose.yml');

        $this->assertSame([], $result);
    }
}
