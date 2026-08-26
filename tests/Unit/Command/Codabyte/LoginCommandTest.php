<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Command\Codabyte;

use Ngramx\Codabyte\ServerTargetResolver;
use Ngramx\Codabyte\SshRunner;
use Ngramx\Command\Codabyte\LoginCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class LoginCommandTest extends TestCase
{
    public function testCommandIsConfiguredCorrectly(): void
    {
        $command = new LoginCommand($this->runner(), new ServerTargetResolver());

        $this->assertSame('codabyte:login', $command->getName());
        $this->assertSame(
            'Log in to the Codabyte server, inside the container running Claude Code',
            $command->getDescription()
        );
    }

    public function testItSshesIntoTheContainerByDefault(): void
    {
        $runner = $this->runner();
        $runner->expects($this->once())
            ->method('run')
            ->with($this->callback(function (array $args): bool {
                $this->assertSame('ssh', $args[0]);
                $this->assertContains('forge@codabyte.gigabyte.software', $args);
                $this->assertStringContainsString("'coding-agent' '/bin/bash'", end($args));

                return true;
            }))
            ->willReturn(0);

        $tester = new CommandTester(new LoginCommand($runner, new ServerTargetResolver()));

        $this->assertSame(0, $tester->execute([]));
        $this->assertStringContainsString('coding-agent on codabyte.gigabyte.software', $tester->getDisplay());
    }

    public function testItPropagatesTheSshExitCode(): void
    {
        $runner = $this->runner();
        $runner->method('run')->willReturn(255);

        $tester = new CommandTester(new LoginCommand($runner, new ServerTargetResolver()));

        $this->assertSame(255, $tester->execute([]));
    }

    public function testDryRunPrintsTheCommandWithoutConnecting(): void
    {
        $runner = $this->runner();
        $runner->expects($this->never())->method('run');

        $tester = new CommandTester(new LoginCommand($runner, new ServerTargetResolver()));
        $tester->execute(['--dry-run' => true]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('ssh', $display);
        $this->assertStringContainsString('forge@codabyte.gigabyte.software', $display);
    }

    public function testRootOptionOverridesTheContainerUser(): void
    {
        $tester = new CommandTester(new LoginCommand($this->runner(), new ServerTargetResolver()));
        $tester->execute(['--dry-run' => true, '--root' => true]);

        $this->assertStringContainsString('root', $tester->getDisplay());
    }

    public function testServerOptionStaysOnTheHost(): void
    {
        $tester = new CommandTester(new LoginCommand($this->runner(), new ServerTargetResolver()));
        $tester->execute(['--dry-run' => true, '--server' => true]);

        $this->assertStringNotContainsString('docker', $tester->getDisplay());
    }

    public function testOptionsOverrideTheTarget(): void
    {
        $tester = new CommandTester(new LoginCommand($this->runner(), new ServerTargetResolver()));
        $tester->execute([
            '--dry-run' => true,
            '--host' => 'example.test',
            '--ssh-user' => 'deploy',
            '--container' => 'agent-2',
            '--workdir' => '/srv',
        ]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('deploy@example.test', $display);
        $this->assertStringContainsString('agent-2', $display);
        $this->assertStringContainsString('/srv', $display);
    }

    public function testATrailingCommandIsForwardedToTheContainer(): void
    {
        $tester = new CommandTester(new LoginCommand($this->runner(), new ServerTargetResolver()));
        $tester->execute(['--dry-run' => true, 'cmd' => ['claude', '--version']]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('claude', $display);
        $this->assertStringNotContainsString('/bin/bash', $display);
    }

    /** @return SshRunner&\PHPUnit\Framework\MockObject\MockObject */
    private function runner(): SshRunner
    {
        $runner = $this->createMock(SshRunner::class);
        $runner->method('hasTty')->willReturn(true);

        return $runner;
    }
}
