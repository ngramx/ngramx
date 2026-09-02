<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Command;

use Ngramx\Command\InitPostmacloneWorkflowCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class InitPostmacloneWorkflowCommandTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/ngramx-init-postmaclone-' . uniqid('', true);
        mkdir($this->testDir, 0755, true);
        chdir($this->testDir);
    }

    protected function tearDown(): void
    {
        chdir(sys_get_temp_dir());
        $this->removeDir($this->testDir);
    }

    public function test_writes_workflow_with_dataset(): void
    {
        $app = new Application();
        $app->add(new InitPostmacloneWorkflowCommand());
        $tester = new CommandTester($app->find('init-postmaclone-workflow'));
        $exit = $tester->execute(['--dataset' => 'earl-kendrick']);

        $this->assertSame(0, $exit);
        $this->assertFileExists($this->testDir . '/.github/workflows/postmaclone-produce.yml');
        $yaml = file_get_contents($this->testDir . '/.github/workflows/postmaclone-produce.yml');
        $this->assertIsString($yaml);
        $this->assertStringContainsString("--dataset 'earl-kendrick'", $yaml);
        $this->assertStringContainsString('OP_SERVICE_ACCOUNT_TOKEN', $yaml);
        $this->assertStringContainsString('0 3 * * *', $yaml);
        $this->assertStringContainsString('pdo_pgsql', $yaml);
        $this->assertStringContainsString('pdo_mysql', $yaml);
    }

    public function test_writes_workflow_with_all_flag_when_no_dataset(): void
    {
        $app = new Application();
        $app->add(new InitPostmacloneWorkflowCommand());
        $tester = new CommandTester($app->find('init-postmaclone-workflow'));
        $exit = $tester->execute([]);

        $this->assertSame(0, $exit);
        $yaml = file_get_contents($this->testDir . '/.github/workflows/postmaclone-produce.yml');
        $this->assertIsString($yaml);
        $this->assertStringContainsString('postmaclone produce --all', $yaml);
    }

    private function removeDir(string $dir): void
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
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
