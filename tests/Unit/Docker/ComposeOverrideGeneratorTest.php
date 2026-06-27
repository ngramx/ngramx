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

        // Clean up temp directory (recursively — some tests create subdirs).
        $this->removeDirectory($this->tempDir);
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

    /**
     * Lay out the on-disk metadata git produces for a linked worktree rooted at
     * the test's temp dir, and return the absolute path of the parent repo's
     * shared git dir that should be bind-mounted into containers.
     */
    private function makeWorktreeLayout(): string
    {
        $repoGit = $this->tempDir . '/parent-git';
        $adminDir = $repoGit . '/worktrees/feature';
        mkdir($adminDir, 0755, true);
        file_put_contents($adminDir . '/commondir', "../..\n");

        // The worktree's .git is a *file* pointing at the per-worktree admin dir.
        file_put_contents($this->tempDir . '/.git', 'gitdir: ' . $adminDir . "\n");

        return (string) realpath($repoGit);
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

        $this->generator->cleanup($composeFile);
        $this->assertFileDoesNotExist($this->tempDir . '/docker-compose.override.yml');
    }

    public function test_it_handles_cleanup_when_no_override_exists(): void
    {
        $composeFile = $this->tempDir . '/docker-compose.yml';
        file_put_contents($composeFile, "services: {}\n");

        $this->assertFileDoesNotExist($this->tempDir . '/docker-compose.override.yml');

        $this->generator->cleanup($composeFile); // Should not throw

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

    public function test_it_injects_worktree_git_mount_into_build_context_services(): void
    {
        $commonDir = $this->makeWorktreeLayout();

        $composeFile = $this->createComposeFile([
            'services' => [
                'app' => [
                    'build' => '.',
                    'ports' => ['80:80'],
                ],
                'db' => [
                    'image' => 'postgres',
                    'ports' => ['5432:5432'],
                ],
            ],
        ]);

        $this->generator->generate($composeFile, 1000, 'ns');

        $override = $this->parseOverride();

        // The build-context service gets the parent git dir bind-mounted at the
        // same absolute path plus the safe.directory guard.
        $this->assertContains(
            $commonDir . ':' . $commonDir,
            $override['services']['app']['volumes']
        );
        $this->assertSame('1', $override['services']['app']['environment']['GIT_CONFIG_COUNT']);
        $this->assertSame('safe.directory', $override['services']['app']['environment']['GIT_CONFIG_KEY_0']);
        $this->assertSame('*', $override['services']['app']['environment']['GIT_CONFIG_VALUE_0']);

        // The image-only service does not run the project entrypoint, so it is
        // left without the git mount.
        $this->assertArrayNotHasKey('volumes', $override['services']['db']);
        $this->assertArrayNotHasKey('environment', $override['services']['db']);
    }

    public function test_git_mount_resolves_inside_container_at_pointer_path(): void
    {
        // The whole point of mounting the common dir at the same absolute path
        // is that the worktree's gitdir pointer resolves unchanged. Assert the
        // mounted path is exactly the prefix of the gitdir pointer the .git file
        // references, so a container that sees the mount can follow the chain.
        $commonDir = $this->makeWorktreeLayout();

        $composeFile = $this->createComposeFile([
            'services' => [
                'app' => ['build' => '.'],
            ],
        ]);

        $this->generator->generate($composeFile, 0, 'ns');

        $override = $this->parseOverride();
        $mount = $override['services']['app']['volumes'][0];
        [$hostPath, $containerPath] = explode(':', $mount, 2);

        $this->assertSame($hostPath, $containerPath, 'mount must be src == dest');

        // gitdir pointer is <commonDir>/worktrees/feature, which lives under the
        // mounted host path — so it is reachable inside the container.
        $this->assertStringStartsWith($hostPath . '/worktrees/', $commonDir . '/worktrees/feature');
    }

    public function test_regeneration_preserves_git_mount_and_never_touches_user_override(): void
    {
        $commonDir = $this->makeWorktreeLayout();

        $composeFile = $this->createComposeFile([
            'services' => [
                'app' => [
                    'build' => '.',
                    'ports' => ['80:80'],
                ],
            ],
        ]);

        // A user customisation lives in the separate, never-regenerated file.
        $userFile = $this->tempDir . '/docker-compose.user.yml';
        $userContents = "services:\n  app:\n    environment:\n      MY_FLAG: \"1\"\n";
        file_put_contents($userFile, $userContents);

        // First generation, then a regeneration with a different offset (as a
        // later `ngramx up` would do).
        $this->generator->generate($composeFile, 1000, 'ns');
        $this->generator->generate($composeFile, 2000, 'ns');

        $override = $this->parseOverride();

        // The git mount survives regeneration...
        $this->assertContains(
            $commonDir . ':' . $commonDir,
            $override['services']['app']['volumes']
        );

        // ...and the user override is left completely untouched.
        $this->assertFileExists($userFile);
        $this->assertSame($userContents, file_get_contents($userFile));
    }

    public function test_it_does_not_inject_git_mount_for_a_normal_checkout(): void
    {
        // A normal checkout keeps .git as a directory inside the project.
        mkdir($this->tempDir . '/.git', 0755, true);

        $composeFile = $this->createComposeFile([
            'services' => [
                'app' => [
                    'build' => '.',
                    'ports' => ['80:80'],
                ],
            ],
        ]);

        $this->generator->generate($composeFile, 1000, 'ns');

        $override = $this->parseOverride();
        $this->assertArrayNotHasKey('volumes', $override['services']['app']);
        $this->assertArrayNotHasKey('environment', $override['services']['app']);
    }

    public function test_it_writes_override_next_to_compose_file_in_subdirectory(): void
    {
        $subDir = $this->tempDir . '/docker';
        mkdir($subDir);

        $composeFile = $subDir . '/docker-compose.yml';
        file_put_contents($composeFile, Yaml::dump([
            'services' => [
                'redis' => [
                    'image' => 'redis',
                    'ports' => ['6379:6379'],
                ],
            ],
        ]));

        $this->generator->generate($composeFile, 8300, 'ngramx-projects-hydra');

        $this->assertFileExists($subDir . '/docker-compose.override.yml');
        $this->assertFileDoesNotExist($this->tempDir . '/docker-compose.override.yml');
    }

    public function test_it_cleans_up_override_from_subdirectory(): void
    {
        $subDir = $this->tempDir . '/docker';
        mkdir($subDir);

        $composeFile = $subDir . '/docker-compose.yml';
        file_put_contents($composeFile, Yaml::dump([
            'services' => [
                'redis' => [
                    'image' => 'redis',
                    'ports' => ['6379:6379'],
                ],
            ],
        ]));

        $this->generator->generate($composeFile, 8300, null);
        $this->assertFileExists($subDir . '/docker-compose.override.yml');

        $this->generator->cleanup($composeFile);
        $this->assertFileDoesNotExist($subDir . '/docker-compose.override.yml');
    }

    public function test_override_path_matches_compose_files_layered_files(): void
    {
        $subDir = $this->tempDir . '/docker';
        mkdir($subDir);

        $composeFile = $subDir . '/docker-compose.yml';
        file_put_contents($composeFile, Yaml::dump([
            'services' => [
                'app' => [
                    'image' => 'nginx',
                    'ports' => ['80:80'],
                ],
            ],
        ]));

        $this->generator->generate($composeFile, 1000, null);

        $layered = \Ngramx\Docker\ComposeFiles::layeredFiles($composeFile);
        $this->assertNotEmpty($layered);
        $this->assertSame($subDir . '/docker-compose.override.yml', $layered[0]);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseOverride(): array
    {
        $contents = file_get_contents($this->tempDir . '/docker-compose.override.yml');
        $this->assertNotFalse($contents, 'Failed to read override file');

        $parsed = Yaml::parse($contents, Yaml::PARSE_CUSTOM_TAGS);

        // Unwrap !override / !reset tagged port values so assertions can index
        // service arrays uniformly.
        foreach ($parsed['services'] ?? [] as $name => $service) {
            if (isset($service['ports']) && $service['ports'] instanceof \Symfony\Component\Yaml\Tag\TaggedValue) {
                $parsed['services'][$name]['ports'] = $service['ports']->getValue();
            }
        }

        return $parsed;
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
