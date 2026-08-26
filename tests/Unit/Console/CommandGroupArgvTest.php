<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Console;

use Ngramx\Console\CommandGroupArgv;
use PHPUnit\Framework\TestCase;

class CommandGroupArgvTest extends TestCase
{
    private const GROUPS = ['codabyte', 'n8n'];

    public function testSpaceSeparatedSubcommandBecomesColonSeparated(): void
    {
        $this->assertSame(
            ['ngramx', 'codabyte:login'],
            CommandGroupArgv::rewrite(['ngramx', 'codabyte', 'login'], self::GROUPS)
        );
    }

    public function testOptionsAndArgumentsSurviveTheRewrite(): void
    {
        $this->assertSame(
            ['ngramx', 'codabyte:login', '--root', '--', 'claude', '--version'],
            CommandGroupArgv::rewrite(
                ['ngramx', 'codabyte', 'login', '--root', '--', 'claude', '--version'],
                self::GROUPS
            )
        );
    }

    public function testColonSeparatedFormIsLeftAlone(): void
    {
        $argv = ['ngramx', 'codabyte:login', '--dry-run'];

        $this->assertSame($argv, CommandGroupArgv::rewrite($argv, self::GROUPS));
    }

    public function testBareGroupListsTheNamespace(): void
    {
        $this->assertSame(
            ['ngramx', 'list', 'codabyte'],
            CommandGroupArgv::rewrite(['ngramx', 'codabyte'], self::GROUPS)
        );
    }

    public function testBareGroupWithAnOptionStillListsTheNamespace(): void
    {
        $this->assertSame(
            ['ngramx', 'list', 'codabyte', '--raw'],
            CommandGroupArgv::rewrite(['ngramx', 'codabyte', '--raw'], self::GROUPS)
        );
    }

    public function testGlobalOptionsBeforeTheGroupAreKept(): void
    {
        $this->assertSame(
            ['ngramx', '-v', 'codabyte:login'],
            CommandGroupArgv::rewrite(['ngramx', '-v', 'codabyte', 'login'], self::GROUPS)
        );
    }

    public function testUnrelatedCommandsAreUntouched(): void
    {
        $argv = ['ngramx', 'up', '--port-offset', '10'];

        $this->assertSame($argv, CommandGroupArgv::rewrite($argv, self::GROUPS));
    }

    public function testACommandThatMerelyStartsWithAGroupNameIsUntouched(): void
    {
        $argv = ['ngramx', 'codabyte-something', 'login'];

        $this->assertSame($argv, CommandGroupArgv::rewrite($argv, self::GROUPS));
    }

    public function testNoCommandAtAllIsUntouched(): void
    {
        $argv = ['ngramx', '--version'];

        $this->assertSame($argv, CommandGroupArgv::rewrite($argv, self::GROUPS));
        $this->assertSame(['ngramx'], CommandGroupArgv::rewrite(['ngramx'], self::GROUPS));
    }

    public function testOtherGroupsWorkToo(): void
    {
        $this->assertSame(
            ['ngramx', 'n8n:export'],
            CommandGroupArgv::rewrite(['ngramx', 'n8n', 'export'], self::GROUPS)
        );
    }
}
