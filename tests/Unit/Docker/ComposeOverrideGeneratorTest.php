<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Docker;

use Ngramx\Docker\ComposeOverrideGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class ComposeOverrideGeneratorTest extends TestCase
{
    private ComposeOverrideGenerator $generator;
    private string $tempDir;
    private string $originalDir;

    protected function setUp(): void
    {
        $this->generator = new ComposeOverrideGenerator();
        $this->tempDir = sys_get_temp_dir() . '/ngramx-test-' . uniqid();
        mkdir($this->tempDir);

        // Save original directory and change to temp dir
        $cwd = getcwd();
        $this->originalDir = $cwd !== false ? $cwd : '/';
        chdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        // Restore original directory
        chdir($this->originalDir);

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

    public function test_it_does_not_generate_override_for_zero_offset(): void
    {
        $composeFile = $this->createComposeFile([
            'services' => [
                'app' => [
                    'image' => 'nginx',
                    'ports' => ['80:80'],
                ],
            ],
        ]);

        $this->generator->generate($composeFile, 0, null);

        $this->assertFileDoesNotExist($this->tempDir . '/docker-compose.override.yml');
    }

    public function test_it_generates_override_with_port_offset(): void
    {
        $composeFile = $this->createComposeFile([
            'services' => [
                'app' => [
                    'image' => 'nginx',
                    'ports' => ['80:80'],
                ],
            ],
        ]);

        $this->generator->generate($composeFile, 1000, null);

        $this->assertFileExists($this->tempDir . '/docker-compose.override.yml');
    }

    public function test_it_applies_offset_to_simple_port_mapping(): void
    {
        $composeFile = $this->createComposeFile([
            'services' => [
                'app' => [
                    'image' => 'nginx',
                    'ports' => ['80:80'],
                ],
            ],
        ]);

        $this->generator->generate($composeFile, 1000, null);

        $overrideContent = file_get_contents($this->tempDir . '/docker-compose.override.yml');
        $this->assertNotFalse($overrideContent, 'Failed to read override file');
        $override = Yaml::parse($overrideContent, Yaml::PARSE_CUSTOM_TAGS);

        // Ports with !override tag are returned as TaggedValue objects
        $ports = $override['services']['app']['ports'];
        if ($ports instanceof \Symfony\Component\Yaml\Tag\TaggedValue) {
            $ports = $ports->getValue();
        }

        $this->assertEquals(['1080:80'], $ports);
    }

    public function test_it_applies_offset_to_multiple_ports(): void
    {
        $composeFile = $this->createComposeFile([
            'services' => [
                'app' => [
                    'image' => 'nginx',
                    'ports' => ['80:80', '443:443'],
                ],
            ],
        ]);

        $this->generator->generate($composeFile, 1000, null);

        $overrideContent = file_get_contents($this->tempDir . '/docker-compose.override.yml');
        $this->assertNotFalse($overrideContent, 'Failed to read override file');
        $override = Yaml::parse($overrideContent, Yaml::PARSE_CUSTOM_TAGS);

        // Ports with !override tag are returned as TaggedValue objects
        $ports = $override['services']['app']['ports'];
        if ($ports instanceof \Symfony\Component\Yaml\Tag\TaggedValue) {
            $ports = $ports->getValue();
        }

        $this->assertEquals(['1080:80', '1443:443'], $ports);
    }

    public function test_it_applies_offset_to_interface_specific_ports(): void
    {
        $composeFile = $this->createComposeFile([
            'services' => [
                'app' => [
                    'image' => 'nginx',
                    'ports' => ['127.0.0.1:80:80'],
                ],
            ],
        ]);

        $this->generator->generate($composeFile, 1000, null);

        $overrideContent = file_get_contents($this->tempDir . '/docker-compose.override.yml');
        $this->assertNotFalse($overrideContent, 'Failed to read override file');
        $override = Yaml::parse($overrideContent, Yaml::PARSE_CUSTOM_TAGS);

        // Ports with !override tag are returned as TaggedValue objects
        $ports = $override['services']['app']['ports'];
        if ($ports instanceof \Symfony\Component\Yaml\Tag\TaggedValue) {
            $ports = $ports->getValue();
        }

        $this->assertEquals(['127.0.0.1:1080:80'], $ports);
    }

    public function test_it_offsets_interpolated_port_default_without_corrupting_syntax(): void
    {
        $composeFile = $this->createComposeFile([
            'services' => [
                'pwa-preview' => [
                    'image' => 'node',
                    'ports' => ['${EK_PWA_PREVIEW_PORT:-3827}:4173'],
                ],
            ],
        ]);

        $this->generator->generate($composeFile, 1000, null);

        $overrideContent = file_get_contents($this->tempDir . '/docker-compose.override.yml');
        $this->assertNotFalse($overrideContent, 'Failed to read override file');

        // The interpolation block must stay intact (no extra colon, closing brace present).
        $this->assertStringNotContainsString('${EK_PWA_PREVIEW_PORT:3827', $overrideContent);

        $override = Yaml::parse($overrideContent, Yaml::PARSE_CUSTOM_TAGS);
        $ports = $override['services']['pwa-preview']['ports'];
        if ($ports instanceof \Symfony\Component\Yaml\Tag\TaggedValue) {
            $ports = $ports->getValue();
        }

        $this->assertEquals(['${EK_PWA_PREVIEW_PORT:-4827}:4173'], $ports);
    }

    public function test_it_preserves_interpolated_port_without_default(): void
    {
        $composeFile = $this->createComposeFile([
            'services' => [
                'pwa-preview' => [
                    'image' => 'node',
                    'ports' => ['${EK_PWA_PREVIEW_PORT}:4173'],
                ],
            ],
        ]);

        $this->generator->generate($composeFile, 1000, null);

        $overrideContent = file_get_contents($this->tempDir . '/docker-compose.override.yml');
        $this->assertNotFalse($overrideContent, 'Failed to read override file');

        $override = Yaml::parse($overrideContent, Yaml::PARSE_CUSTOM_TAGS);
        $ports = $override['services']['pwa-preview']['ports'];
        if ($ports instanceof \Symfony\Component\Yaml\Tag\TaggedValue) {
            $ports = $ports->getValue();
        }

        $this->assertEquals(['${EK_PWA_PREVIEW_PORT}:4173'], $ports);
    }

    public function test_it_applies_offset_to_multiple_services(): void
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

        $this->generator->generate($composeFile, 1000, null);

        $overrideContent = file_get_contents($this->tempDir . '/docker-compose.override.yml');
        $this->assertNotFalse($overrideContent, 'Failed to read override file');
        $override = Yaml::parse($overrideContent, Yaml::PARSE_CUSTOM_TAGS);

        // Ports with !override tag are returned as TaggedValue objects
        $appPorts = $override['services']['app']['ports'];
        if ($appPorts instanceof \Symfony\Component\Yaml\Tag\TaggedValue) {
            $appPorts = $appPorts->getValue();
        }
        $dbPorts = $override['services']['db']['ports'];
        if ($dbPorts instanceof \Symfony\Component\Yaml\Tag\TaggedValue) {
            $dbPorts = $dbPorts->getValue();
        }

        $this->assertEquals(['1080:80'], $appPorts);
        $this->assertEquals(['6432:5432'], $dbPorts);
    }

    public function test_it_includes_header_comment(): void
    {
        $composeFile = $this->createComposeFile([
            'services' => [
                'app' => [
                    'image' => 'nginx',
                    'ports' => ['80:80'],
                ],
            ],
        ]);

        $this->generator->generate($composeFile, 1000, null);

        $overrideContent = file_get_contents($this->tempDir . '/docker-compose.override.yml');
        $this->assertIsString($overrideContent);

        $this->assertStringContainsString('Generated by Ngramx CLI', $overrideContent);
        $this->assertStringContainsString('DO NOT EDIT MANUALLY', $overrideContent);
    }

    public function test_it_cleans_up_override_file(): void
    {
        $composeFile = $this->createComposeFile([
            'services' => [
                'app' => [
                    'image' => 'nginx',
                    'ports' => ['80:80'],
                ],
            ],
        ]);

        $this->generator->generate($composeFile, 1000, null);
        $this->assertFileExists($this->tempDir . '/docker-compose.override.yml');

        $this->generator->cleanup();
        $this->assertFileDoesNotExist($this->tempDir . '/docker-compose.override.yml');
    }

    public function test_it_handles_cleanup_when_no_override_exists(): void
    {
        $this->assertFileDoesNotExist($this->tempDir . '/docker-compose.override.yml');

        $this->generator->cleanup(); // Should not throw

        $this->assertFileDoesNotExist($this->tempDir . '/docker-compose.override.yml');
    }

    public function test_it_prefixes_container_names_with_namespace(): void
    {
        $composeFile = $this->createComposeFile([
            'services' => [
                'app' => [
                    'image' => 'nginx',
                    'container_name' => 'my-app',
                    'ports' => ['80:80'],
                ],
                'db' => [
                    'image' => 'postgres',
                    'container_name' => 'my-db',
                ],
            ],
        ]);

        $this->generator->generate($composeFile, 0, 'test-namespace');

        $overrideContent = file_get_contents($this->tempDir . '/docker-compose.override.yml');
        $this->assertNotFalse($overrideContent, 'Failed to read override file');
        $override = Yaml::parse($overrideContent, Yaml::PARSE_CUSTOM_TAGS);

        $this->assertEquals('test-namespace-my-app', $override['services']['app']['container_name']);
        $this->assertEquals('test-namespace-my-db', $override['services']['db']['container_name']);
    }

    public function test_it_applies_both_port_offset_and_prefixes_container_names(): void
    {
        $composeFile = $this->createComposeFile([
            'services' => [
                'app' => [
                    'image' => 'nginx',
                    'container_name' => 'my-app',
                    'ports' => ['80:80'],
                ],
            ],
        ]);

        $this->generator->generate($composeFile, 1000, 'test-namespace');

        $overrideContent = file_get_contents($this->tempDir . '/docker-compose.override.yml');
        $this->assertNotFalse($overrideContent, 'Failed to read override file');
        $override = Yaml::parse($overrideContent, Yaml::PARSE_CUSTOM_TAGS);

        // Ports with !override tag are returned as TaggedValue objects
        $ports = $override['services']['app']['ports'];
        if ($ports instanceof \Symfony\Component\Yaml\Tag\TaggedValue) {
            $ports = $ports->getValue();
        }

        $this->assertEquals(['1080:80'], $ports);
        $this->assertEquals('test-namespace-my-app', $override['services']['app']['container_name']);
    }

    public function test_it_throws_exception_for_nonexistent_compose_file(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Compose file not found');

        $this->generator->generate('/nonexistent/docker-compose.yml', 1000, null);
    }

    public function test_it_generates_override_with_no_host_mapping(): void
    {
        $composeFile = $this->createComposeFile([
            'services' => [
                'app' => [
                    'image' => 'nginx',
                    'ports' => ['80:80'],
                ],
            ],
        ]);

        $this->generator->generate($composeFile, 0, null, true);

        $this->assertFileExists($this->tempDir . '/docker-compose.override.yml');
    }

    public function test_it_removes_all_ports_with_no_host_mapping(): void
    {
        $composeFile = $this->createComposeFile([
            'services' => [
                'app' => [
                    'image' => 'nginx',
                    'ports' => ['80:80', '443:443'],
                ],
                'db' => [
                    'image' => 'postgres',
                    'ports' => ['5432:5432'],
                ],
            ],
        ]);

        $this->generator->generate($composeFile, 0, null, true);

        $overrideContent = file_get_contents($this->tempDir . '/docker-compose.override.yml');
        $this->assertNotFalse($overrideContent, 'Failed to read override file');
        $override = Yaml::parse($overrideContent, Yaml::PARSE_CUSTOM_TAGS);

        // Ports with !reset tag are returned as TaggedValue objects
        $appPorts = $override['services']['app']['ports'];
        $this->assertInstanceOf(\Symfony\Component\Yaml\Tag\TaggedValue::class, $appPorts);
        $this->assertEquals('reset', $appPorts->getTag());
        $this->assertEquals([], $appPorts->getValue());

        $dbPorts = $override['services']['db']['ports'];
        $this->assertInstanceOf(\Symfony\Component\Yaml\Tag\TaggedValue::class, $dbPorts);
        $this->assertEquals('reset', $dbPorts->getTag());
        $this->assertEquals([], $dbPorts->getValue());
    }

    public function test_it_applies_namespace_with_no_host_mapping(): void
    {
        $composeFile = $this->createComposeFile([
            'services' => [
                'app' => [
                    'image' => 'nginx',
                    'container_name' => 'my-app',
                    'ports' => ['80:80'],
                ],
            ],
        ]);

        $this->generator->generate($composeFile, 0, 'test-namespace', true);

        $overrideContent = file_get_contents($this->tempDir . '/docker-compose.override.yml');
        $this->assertNotFalse($overrideContent, 'Failed to read override file');
        $override = Yaml::parse($overrideContent, Yaml::PARSE_CUSTOM_TAGS);

        // Ports with !reset tag are returned as TaggedValue objects
        $ports = $override['services']['app']['ports'];
        $this->assertInstanceOf(\Symfony\Component\Yaml\Tag\TaggedValue::class, $ports);
        $this->assertEquals('reset', $ports->getTag());
        $this->assertEquals([], $ports->getValue());

        $this->assertEquals('test-namespace-my-app', $override['services']['app']['container_name']);
    }

    public function test_no_host_mapping_takes_precedence_over_port_offset(): void
    {
        $composeFile = $this->createComposeFile([
            'services' => [
                'app' => [
                    'image' => 'nginx',
                    'ports' => ['80:80'],
                ],
            ],
        ]);

        // Even with port offset, noHostMapping should result in empty ports
        $this->generator->generate($composeFile, 1000, null, true);

        $overrideContent = file_get_contents($this->tempDir . '/docker-compose.override.yml');
        $this->assertNotFalse($overrideContent, 'Failed to read override file');
        $override = Yaml::parse($overrideContent, Yaml::PARSE_CUSTOM_TAGS);

        // Ports with !reset tag are returned as TaggedValue objects
        $ports = $override['services']['app']['ports'];
        $this->assertInstanceOf(\Symfony\Component\Yaml\Tag\TaggedValue::class, $ports);
        $this->assertEquals('reset', $ports->getTag());
        $this->assertEquals([], $ports->getValue());
    }

    /**
     * @param array<string, mixed> $content
     */
    private function createComposeFile(array $content): string
    {
        $filename = $this->tempDir . '/docker-compose.yml';
        $yaml = Yaml::dump($content);
        file_put_contents($filename, $yaml);
        return $filename;
    }
}
