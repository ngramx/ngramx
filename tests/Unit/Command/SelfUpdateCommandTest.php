<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Command;

use Ngramx\Command\SelfUpdateCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class SelfUpdateCommandTest extends TestCase
{
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $application = new Application();
        $application->add(new SelfUpdateCommand());

        $command = $application->find('update');
        $this->commandTester = new CommandTester($command);
    }

    public function test_it_fails_when_not_running_as_phar(): void
    {
        // When running from source (not as PHAR), should fail with helpful message
        $this->commandTester->execute([]);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Update is only available when running as PHAR', $output);
        $this->assertStringContainsString('git pull origin main', $output);
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function test_it_has_check_option(): void
    {
        $this->commandTester->execute(['--check' => true]);

        $output = $this->commandTester->getDisplay();
        // Should still fail when not running as PHAR, but verify the option is recognized
        $this->assertStringContainsString('Update is only available when running as PHAR', $output);
    }

    public function test_it_has_force_option(): void
    {
        $this->commandTester->execute(['--force' => true]);

        $output = $this->commandTester->getDisplay();
        // Should still fail when not running as PHAR, but verify the option is recognized
        $this->assertStringContainsString('Update is only available when running as PHAR', $output);
    }

    public function test_command_is_named_update(): void
    {
        $application = new Application();
        $command = new SelfUpdateCommand();
        $application->add($command);

        // Test that command is named 'update'
        $this->assertTrue($application->has('update'));
        // Should not have old aliases
        $this->assertFalse($application->has('selfupdate'));
        $this->assertFalse($application->has('self-update'));
    }

    public function test_command_description(): void
    {
        $command = new SelfUpdateCommand();

        $this->assertEquals('update', $command->getName());
        $this->assertEquals('Update Ngramx CLI to the latest version', $command->getDescription());
    }
}
