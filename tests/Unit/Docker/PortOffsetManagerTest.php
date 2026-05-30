<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Docker;

use Ngramx\Docker\PortOffsetManager;
use PHPUnit\Framework\TestCase;

class PortOffsetManagerTest extends TestCase
{
    private PortOffsetManager $manager;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->manager = new PortOffsetManager();
        $this->tempDir = sys_get_temp_dir() . '/ngramx-test-' . uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        $files = glob($this->tempDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function test_it_extracts_simple_port_mappings(): void
    {
        $composeFile = $this->createComposeFile([
            'services' => [
                'app' => [
                    'image' => 'nginx',
                    'ports' => ['80:80', '443:443'],
                ],
            ],
        ]);

        $ports = $this->manager->extractBasePorts($composeFile);

        $this->assertCount(2, $ports);
        $this->assertContains(80, $ports);
        $this->assertContains(443, $ports);
    }

    public function test_it_extracts_ports_with_interface(): void
    {
        $composeFile = $this->createComposeFile([
            'services' => [
                'app' => [
                    'image' => 'nginx',
                    'ports' => ['127.0.0.1:8080:80'],
                ],
            ],
        ]);

        $ports = $this->manager->extractBasePorts($composeFile);

        $this->assertCount(1, $ports);
        $this->assertContains(8080, $ports);
    }

    public function test_it_extracts_interpolated_port_default(): void
    {
        $composeFile = $this->createComposeFile([
            'services' => [
                'pwa-preview' => [
                    'image' => 'node',
                    'ports' => ['${EK_PWA_PREVIEW_PORT:-3827}:4173'],
                ],
            ],
        ]);

        $ports = $this->manager->extractBasePorts($composeFile);

        $this->assertCount(1, $ports);
        $this->assertContains(3827, $ports);
    }

    public function test_it_skips_interpolated_port_without_default(): void
    {
        $composeFile = $this->createComposeFile([
            'services' => [
                'pwa-preview' => [
                    'image' => 'node',
                    'ports' => ['${EK_PWA_PREVIEW_PORT}:4173'],
                ],
            ],
        ]);

        $ports = $this->manager->extractBasePorts($composeFile);

        $this->assertEmpty($ports);
    }

    public function test_it_extracts_ports_from_multiple_services(): void
    {
        $composeFile = $this->createComposeFile([
            'services' => [
                'app' => [
                    'image' => 'nginx',
                    'ports' => ['80:80'],
                ],
                'db' => [
                    'image' => 'postgres',
                    'ports' => ['5432:5432'],
                ],
            ],
        ]);

        $ports = $this->manager->extractBasePorts($composeFile);

        $this->assertCount(2, $ports);
        $this->assertContains(80, $ports);
        $this->assertContains(5432, $ports);
    }

    public function test_it_removes_duplicate_ports(): void
    {
        $composeFile = $this->createComposeFile([
            'services' => [
                'app1' => [
                    'image' => 'nginx',
                    'ports' => ['80:80'],
                ],
                'app2' => [
                    'image' => 'nginx',
                    'ports' => ['80:8080'],
                ],
            ],
        ]);

        $ports = $this->manager->extractBasePorts($composeFile);

        $this->assertCount(1, $ports);
        $this->assertContains(80, $ports);
    }

    public function test_it_handles_services_without_ports(): void
    {
        $composeFile = $this->createComposeFile([
            'services' => [
                'app' => [
                    'image' => 'nginx',
                ],
            ],
        ]);

        $ports = $this->manager->extractBasePorts($composeFile);

        $this->assertEmpty($ports);
    }

    public function test_it_returns_empty_array_for_nonexistent_file(): void
    {
        $ports = $this->manager->extractBasePorts('/nonexistent/docker-compose.yml');

        $this->assertEmpty($ports);
    }

    public function test_it_returns_empty_array_for_file_without_services(): void
    {
        $composeFile = $this->createComposeFile([
            'version' => '3.8',
        ]);

        $ports = $this->manager->extractBasePorts($composeFile);

        $this->assertEmpty($ports);
    }

    public function test_it_finds_available_offset_when_base_ports_are_free(): void
    {
        $basePorts = [80, 443];

        $offset = $this->manager->findAvailableOffset($basePorts);

        // Should return 0 if base ports are available
        $this->assertGreaterThanOrEqual(0, $offset);
    }

    public function test_it_returns_zero_for_empty_base_ports(): void
    {
        $offset = $this->manager->findAvailableOffset([]);

        $this->assertEquals(0, $offset);
    }

    /**
     * @param array<string, mixed> $content
     */
    private function createComposeFile(array $content): string
    {
        $filename = $this->tempDir . '/docker-compose-' . uniqid() . '.yml';
        $yaml = \Symfony\Component\Yaml\Yaml::dump($content);
        file_put_contents($filename, $yaml);
        return $filename;
    }
}
