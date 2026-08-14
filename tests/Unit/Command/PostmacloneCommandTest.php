<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Command;

use Ngramx\Command\PostmacloneCommand;
use Ngramx\Config\ConfigLoader;
use Ngramx\Config\Validator\ConfigValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class PostmacloneCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pm-cmd-' . uniqid('', true);
        mkdir($this->dir, 0700, true);
        $compose = dirname(__DIR__, 2) . '/fixtures/postmaclone/compose-postgres.yml';
        $dump = dirname(__DIR__, 2) . '/fixtures/postmaclone/users.sql';
        file_put_contents($this->dir . '/ngramx.yml', <<<YAML
version: "1.0"
docker:
  compose_file: "{$compose}"
  primary_service: "app"
  app_url: "http://localhost"
postmaclone:
  engine: postgres
  seed: 42
  tables:
    users:
      email: safeEmail
      first_name: firstName
YAML);
        copy($dump, $this->dir . '/users.sql');
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/ngramx.yml');
        @unlink($this->dir . '/users.sql');
        @unlink($this->dir . '/out.sql');
        @rmdir($this->dir);
    }

    public function test_sql_option_writes_anonymization_script(): void
    {
        $cwd = getcwd();
        chdir($this->dir);
        try {
            $command = new PostmacloneCommand(new ConfigLoader(new ConfigValidator()));
            $tester = new CommandTester($command);
            $exit = $tester->execute([
                '--sql' => true,
                '--from' => $this->dir . '/users.sql',
                '--output' => $this->dir . '/out.sql',
            ]);
            $this->assertSame(0, $exit);
            $this->assertFileExists($this->dir . '/out.sql');
            $sql = file_get_contents($this->dir . '/out.sql');
            $this->assertIsString($sql);
            $this->assertStringContainsString('UPDATE "users"', $sql);
            $this->assertStringContainsString('"email"', $sql);
            $this->assertStringNotContainsString('"status"', $sql);
        } finally {
            if (is_string($cwd)) {
                chdir($cwd);
            }
        }
    }

    public function test_status_with_no_lock(): void
    {
        $cwd = getcwd();
        chdir($this->dir);
        try {
            $command = new PostmacloneCommand(new ConfigLoader(new ConfigValidator()));
            $tester = new CommandTester($command);
            $exit = $tester->execute(['action' => 'status']);
            $this->assertSame(0, $exit);
            $this->assertStringContainsString('No active Post Maclone clone', $tester->getDisplay());
        } finally {
            if (is_string($cwd)) {
                chdir($cwd);
            }
        }
    }

    public function test_doctor_passes_when_s3_not_required(): void
    {
        $cwd = getcwd();
        chdir($this->dir);
        try {
            $command = new PostmacloneCommand(new ConfigLoader(new ConfigValidator()));
            $tester = new CommandTester($command);
            $exit = $tester->execute(['action' => 'doctor']);
            $this->assertSame(0, $exit);
            $this->assertStringContainsString('Post Maclone doctor', $tester->getDisplay());
            $this->assertStringContainsString('not using an S3', $tester->getDisplay());
        } finally {
            if (is_string($cwd)) {
                chdir($cwd);
            }
        }
    }

    public function test_sql_requires_from(): void
    {
        $cwd = getcwd();
        chdir($this->dir);
        try {
            $command = new PostmacloneCommand(new ConfigLoader(new ConfigValidator()));
            $tester = new CommandTester($command);
            $exit = $tester->execute(['--sql' => true]);
            $this->assertSame(1, $exit);
        } finally {
            if (is_string($cwd)) {
                chdir($cwd);
            }
        }
    }
}
