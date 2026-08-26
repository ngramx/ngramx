<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Codabyte;

use Ngramx\Codabyte\ServerTarget;
use PHPUnit\Framework\TestCase;

class ServerTargetTest extends TestCase
{
    public function testDefaultsPointAtTheCodingAgentServer(): void
    {
        $target = new ServerTarget();

        $this->assertSame('codabyte.gigabyte.software', $target->host);
        $this->assertSame('forge@codabyte.gigabyte.software', $target->sshDestination());
        $this->assertSame('coding-agent', $target->container);
        $this->assertSame('node', $target->containerUser);
        $this->assertSame('/workspace', $target->workdir);
        $this->assertNull($target->port);
    }

    public function testInteractiveShellExecsIntoTheContainer(): void
    {
        $args = (new ServerTarget())->sshArgs();

        $this->assertSame('ssh', $args[0]);
        $this->assertSame('-t', $args[1]);
        $this->assertSame('forge@codabyte.gigabyte.software', $args[2]);
        $this->assertCount(4, $args);

        $remote = $args[3];
        $this->assertStringContainsString("'docker' 'exec' '-i' '-t'", $remote);
        $this->assertStringContainsString("'-u' 'node'", $remote);
        $this->assertStringContainsString("'-w' '/workspace'", $remote);
        $this->assertStringContainsString("'coding-agent' '/bin/bash'", $remote);
    }

    public function testInteractiveShellSetsABrandedPrompt(): void
    {
        $remote = (new ServerTarget())->sshArgs()[3];

        $this->assertStringContainsString("'-e' 'PS1=", $remote);
        $this->assertStringContainsString('coding-agent@codabyte.gigabyte.software', $remote);
    }

    public function testExplicitCommandReplacesTheShellAndDropsThePrompt(): void
    {
        $remote = (new ServerTarget())->sshArgs(['claude', '--version'])[3];

        $this->assertStringEndsWith("'coding-agent' 'claude' '--version'", $remote);
        $this->assertStringNotContainsString('PS1=', $remote);
        $this->assertStringNotContainsString('/bin/bash', $remote);
    }

    public function testCommandArgumentsAreQuotedForTheRemoteShell(): void
    {
        $remote = (new ServerTarget())->sshArgs(['bash', '-c', 'echo "hi there"; rm -rf /'])[3];

        // The remote login shell must see one argument, not three commands.
        $this->assertStringEndsWith("'bash' '-c' 'echo \"hi there\"; rm -rf /'", $remote);
    }

    public function testServerModeSkipsTheContainer(): void
    {
        $args = (new ServerTarget())->sshArgs([], false);

        $this->assertSame(['ssh', '-t', 'forge@codabyte.gigabyte.software'], $args);
    }

    public function testServerModeCanRunACommandOnTheHost(): void
    {
        $args = (new ServerTarget())->sshArgs(['docker', 'ps'], false);

        $this->assertSame("'docker' 'ps'", $args[3]);
    }

    public function testWithoutATtyNoTerminalIsRequestedAnywhere(): void
    {
        $args = (new ServerTarget())->sshArgs([], true, false);

        $this->assertNotContains('-t', $args);
        $this->assertStringContainsString("'docker' 'exec' '-i' '-u'", $args[2]);
    }

    public function testPortAndOverridesAreApplied(): void
    {
        $target = new ServerTarget(
            host: 'example.test',
            sshUser: 'deploy',
            container: 'other-agent',
            containerUser: 'root',
            workdir: '/srv',
            port: 2222,
        );

        $args = $target->sshArgs();

        $this->assertSame(['ssh', '-t', '-p', '2222', 'deploy@example.test'], array_slice($args, 0, 5));
        $this->assertStringContainsString("'-u' 'root'", $args[5]);
        $this->assertStringContainsString("'-w' '/srv'", $args[5]);
        $this->assertStringContainsString("'other-agent'", $args[5]);
    }
}
