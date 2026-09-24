<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Postmaclone\Backup\HostEnvironment;
use Ngramx\Postmaclone\Backup\OpSessionEnsurer;
use PHPUnit\Framework\TestCase;

class OpSessionEnsurerTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        foreach (['OP_SERVICE_ACCOUNT_TOKEN', 'OP_ACCOUNT'] as $key) {
            $this->originalEnv[$key] = getenv($key);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }
    }

    public function test_skips_when_service_account_token_set(): void
    {
        putenv('OP_SERVICE_ACCOUNT_TOKEN=test-token');
        (new OpSessionEnsurer())->ensureSignedIn();
        $this->assertTrue(true);
    }

    public function test_wsl_detection_matches_expected_runtime(): void
    {
        // OpSessionEnsurer runs a silent primer `op signin` on WSL before the
        // interactive --raw --force step (stderr discarded so eval hint is hidden).
        $this->assertSame(
            getenv('WSL_DISTRO_NAME') !== false && getenv('WSL_DISTRO_NAME') !== '',
            HostEnvironment::isWsl(),
        );
    }
}
