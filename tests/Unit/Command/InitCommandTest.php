<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Cortex\Command\InitCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class InitCommandTest extends TestCase
{
    private string $testDir;
    private string $testHomeDir;
    private string|false $originalHome;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testDir = sys_get_temp_dir() . '/cortex_init_test_' . uniqid();
        mkdir($this->testDir, 0755, true);

        $this->testHomeDir = sys_get_temp_dir() . '/cortex_home_test_' . uniqid();
        mkdir($this->testHomeDir, 0755, true);

        $this->originalHome = getenv('HOME');
        putenv('HOME=' . $this->testHomeDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->originalHome !== false) {
            putenv('HOME=' . $this->originalHome);
        } else {
            putenv('HOME');
        }

        if (is_dir($this->testDir)) {
            $this->recursiveRemoveDirectory($this->testDir);
        }
        if (is_dir($this->testHomeDir)) {
            $this->recursiveRemoveDirectory($this->testHomeDir);
        }
    }

    public function testInitCreatesDirectoryStructure(): void
    {
        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute([], ['interactive' => false]);

        $this->assertEquals(0, $tester->getStatusCode());
        $this->assertDirectoryExists($this->testDir . '/.cortex');
        $this->assertDirectoryExists($this->testDir . '/.cortex/tickets');
        $this->assertDirectoryExists($this->testDir . '/.cortex/specs');
        $this->assertDirectoryExists($this->testDir . '/.cortex/meetings');
    }

    public function testInitCreatesGitkeep(): void
    {
        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute([], ['interactive' => false]);

        $this->assertFileExists($this->testDir . '/.cortex/tickets/.gitkeep');
    }

    public function testInitCreatesReadme(): void
    {
        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute([], ['interactive' => false]);

        $this->assertFileExists($this->testDir . '/.cortex/README.md');

        $content = file_get_contents($this->testDir . '/.cortex/README.md');
        $this->assertIsString($content, 'Failed to read README.md');
        assert(is_string($content));
        $this->assertStringContainsString('# .cortex Folder', $content);
        $this->assertStringContainsString('Core Principle', $content);
    }

    public function testInitCreatesCortexYml(): void
    {
        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute([], ['interactive' => false]);

        $this->assertFileExists($this->testDir . '/cortex.yml');

        $content = file_get_contents($this->testDir . '/cortex.yml');
        $this->assertIsString($content, 'Failed to read cortex.yml');
        assert(is_string($content));
        $this->assertStringContainsString('version: "1.0"', $content);
        $this->assertStringContainsString('docker:', $content);
        $this->assertStringContainsString('compose_file:', $content);
    }

    public function testInitWithSkipYamlOption(): void
    {
        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute(['--skip-yaml' => true], ['interactive' => false]);

        $this->assertEquals(0, $tester->getStatusCode());
        $this->assertDirectoryExists($this->testDir . '/.cortex');
        $this->assertFileDoesNotExist($this->testDir . '/cortex.yml');
    }

    public function testInitWithSkipClaudeOption(): void
    {
        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute(['--skip-claude' => true], ['interactive' => false]);

        $this->assertEquals(0, $tester->getStatusCode());
        $this->assertDirectoryExists($this->testDir . '/.cortex');
        $this->assertFileExists($this->testDir . '/cortex.yml');
        $this->assertFileDoesNotExist($this->testHomeDir . '/.claude/CLAUDE.md');

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Skipped ~/.claude files', $output);
    }

    public function testInitIsIdempotent(): void
    {
        $command = new InitCommand();
        $tester = $this->createCommandTester($command);
        $tester->execute([], ['interactive' => false]);

        $this->assertEquals(0, $tester->getStatusCode());

        $command2 = new InitCommand();
        $tester2 = $this->createCommandTester($command2);
        $tester2->execute([], ['interactive' => false]);

        $this->assertEquals(0, $tester2->getStatusCode());
        $output = $tester2->getDisplay();
        $this->assertStringContainsString('already exists', $output);
    }

    public function testInitWithForceOverwrites(): void
    {
        mkdir($this->testDir . '/.cortex', 0755, true);
        file_put_contents($this->testDir . '/cortex.yml', 'old content');

        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute(['--force' => true], ['interactive' => false]);

        $this->assertEquals(0, $tester->getStatusCode());

        $content = file_get_contents($this->testDir . '/cortex.yml');
        $this->assertIsString($content, 'Failed to read cortex.yml');
        assert(is_string($content));
        $this->assertStringContainsString('version: "1.0"', $content);
        $this->assertStringNotContainsString('old content', $content);
    }

    public function testInitCreatesClaudeMd(): void
    {
        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute([], ['interactive' => false]);

        $this->assertFileExists($this->testHomeDir . '/.claude/CLAUDE.md');

        $content = file_get_contents($this->testHomeDir . '/.claude/CLAUDE.md');
        $this->assertIsString($content, 'Failed to read CLAUDE.md');
        assert(is_string($content));

        $this->assertStringContainsString('<!-- CORTEX START -->', $content);
        $this->assertStringContainsString('<!-- CORTEX END -->', $content);
        $this->assertStringContainsString('cortex up', $content);
    }

    public function testInitAppendsToExistingClaudeMd(): void
    {
        mkdir($this->testHomeDir . '/.claude', 0755, true);
        file_put_contents($this->testHomeDir . '/.claude/CLAUDE.md', "# My Project\n\nExisting content here.");

        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute([], ['interactive' => false]);

        $content = file_get_contents($this->testHomeDir . '/.claude/CLAUDE.md');
        $this->assertIsString($content, 'Failed to read CLAUDE.md');
        assert(is_string($content));

        $this->assertStringContainsString('# My Project', $content);
        $this->assertStringContainsString('Existing content here.', $content);
        $this->assertStringContainsString('<!-- CORTEX START -->', $content);
        $this->assertStringContainsString('<!-- CORTEX END -->', $content);
        $this->assertStringContainsString('cortex up', $content);

        $existingPos = strpos($content, '# My Project');
        $cortexPos = strpos($content, '<!-- CORTEX START -->');
        $this->assertNotFalse($existingPos);
        $this->assertNotFalse($cortexPos);
        $this->assertLessThan($cortexPos, $existingPos, 'Existing content should come before Cortex section');
    }

    public function testInitUpdatesClaudeMdWhenCortexSectionChanged(): void
    {
        mkdir($this->testHomeDir . '/.claude', 0755, true);
        $existingContent = "# My Project\n\n<!-- CORTEX START -->\nOld cortex content\n<!-- CORTEX END -->";
        file_put_contents($this->testHomeDir . '/.claude/CLAUDE.md', $existingContent);

        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute([], ['interactive' => false]);

        $content = file_get_contents($this->testHomeDir . '/.claude/CLAUDE.md');
        $this->assertIsString($content, 'Failed to read CLAUDE.md');
        assert(is_string($content));

        $this->assertStringNotContainsString('Old cortex content', $content);
        $this->assertStringContainsString('cortex up', $content);
        $this->assertStringContainsString('# My Project', $content);
    }

    public function testInitSyncsAgentTargets(): void
    {
        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute([], ['interactive' => false]);

        $this->assertEquals(0, $tester->getStatusCode());

        // AGENTS.md should be created (default target)
        $this->assertFileExists($this->testDir . '/AGENTS.md');
    }

    public function testInitDisplaysSuccessMessage(): void
    {
        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute([], ['interactive' => false]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Cortex initialized successfully', $output);
        $this->assertStringContainsString('Next steps:', $output);
        $this->assertStringContainsString('cortex up', $output);
    }

    public function testInitSuccessMessageIncludesClaudeMd(): void
    {
        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute([], ['interactive' => false]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('~/.claude/CLAUDE.md', $output);
    }

    private function createCommandTester(InitCommand $command): CommandTester
    {
        $originalDir = getcwd();
        chdir($this->testDir);

        $application = new Application();
        $application->add($command);

        $command = $application->find('init');
        $tester = new CommandTester($command);

        register_shutdown_function(function () use ($originalDir) {
            if ($originalDir !== false) {
                @chdir($originalDir);
            }
        });

        return $tester;
    }

    private function recursiveRemoveDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;

            if (is_dir($path)) {
                $this->recursiveRemoveDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
