<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Postmaclone\Backup\HostEnvironment;
use Ngramx\Postmaclone\Backup\OpAuthProbe;
use PHPUnit\Framework\TestCase;

final class OpAuthProbeTest extends TestCase
{
    public function testAuthGuidanceForMissingSession(): void
    {
        $guidance = OpAuthProbe::authGuidanceForError('no active session found for account gigabytesoftware');

        self::assertStringContainsString('no active session', strtolower($guidance));
        self::assertStringContainsString('OP_SERVICE_ACCOUNT_TOKEN', $guidance);
        self::assertStringContainsString('eval $(op signin)', $guidance);
        if (HostEnvironment::isWsl()) {
            self::assertStringContainsString('WSL', $guidance);
            self::assertStringNotContainsString('desktop app', strtolower($guidance));
        }
    }

    public function testWslSetupStepsLeadWithShellSignin(): void
    {
        $steps = implode("\n", OpAuthProbe::wslHumanSetupSteps(includeAccountAdd: true));

        self::assertStringContainsString('op account add', $steps);
        self::assertStringContainsString('eval $(op signin)', $steps);
        self::assertStringContainsString('WSL', $steps);
        self::assertStringNotContainsString('Integrate with 1Password CLI', $steps);
    }

    public function testSigninAddressConstant(): void
    {
        self::assertSame('gigabytesoftware.1password.com', OpAuthProbe::SIGNIN_ADDRESS);
    }
}
