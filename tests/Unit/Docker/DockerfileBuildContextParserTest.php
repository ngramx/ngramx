<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Docker;

use Ngramx\Docker\DockerfileBuildContextParser;
use PHPUnit\Framework\TestCase;

class DockerfileBuildContextParserTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ngramx-dockerfile-parser-test-' . uniqid();
        mkdir($this->tmpDir . '/docker', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    public function test_it_parses_copy_and_entrypoint_directives(): void
    {
        file_put_contents($this->tmpDir . '/docker/entrypoint.sh', "#!/bin/bash\necho hi\n");
        file_put_contents($this->tmpDir . '/docker/reverb-start.sh', "#!/bin/bash\necho reverb\n");
        file_put_contents($this->tmpDir . '/Dockerfile', <<<'DOCKERFILE'
FROM php:8.5-fpm
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/reverb-start.sh /usr/local/bin/reverb-start.sh
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
DOCKERFILE);

        $parser = new DockerfileBuildContextParser();

        $this->assertSame(
            [
                ['host' => $this->tmpDir . '/docker/entrypoint.sh', 'image' => '/usr/local/bin/entrypoint.sh'],
                ['host' => $this->tmpDir . '/docker/reverb-start.sh', 'image' => '/usr/local/bin/reverb-start.sh'],
            ],
            $parser->copiedFiles($this->tmpDir . '/Dockerfile')
        );
        $this->assertSame('/usr/local/bin/entrypoint.sh', $parser->defaultEntrypointPath($this->tmpDir . '/Dockerfile'));
        $this->assertSame(
            $this->tmpDir . '/docker/reverb-start.sh',
            $parser->hostPathForImagePath($this->tmpDir . '/Dockerfile', '/usr/local/bin/reverb-start.sh')
        );
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
