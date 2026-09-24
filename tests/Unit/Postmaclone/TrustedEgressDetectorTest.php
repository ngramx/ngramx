<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Postmaclone\Connect\TrustedEgressDetector;
use PHPUnit\Framework\TestCase;

class TrustedEgressDetectorTest extends TestCase
{
    private array $originalEnv = [];

    protected function setUp(): void
    {
        foreach ([TrustedEgressDetector::ENV_TRUSTED, TrustedEgressDetector::ENV_FORCE_TUNNEL, 'OP_SERVICE_ACCOUNT_TOKEN'] as $key) {
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

    public function test_direct_when_trusted_env_set(): void
    {
        putenv(TrustedEgressDetector::ENV_TRUSTED . '=1');
        $this->assertTrue((new TrustedEgressDetector())->isDirectMode());
    }

    public function test_tunnel_when_force_tunnel_set(): void
    {
        putenv(TrustedEgressDetector::ENV_TRUSTED . '=1');
        putenv(TrustedEgressDetector::ENV_FORCE_TUNNEL . '=1');
        $this->assertFalse((new TrustedEgressDetector())->isDirectMode());
    }

    public function test_direct_when_service_account_token_set(): void
    {
        putenv('OP_SERVICE_ACCOUNT_TOKEN=token');
        $this->assertTrue((new TrustedEgressDetector())->isDirectMode());
    }

    public function test_tunnel_by_default_on_dev_laptop(): void
    {
        $this->assertFalse((new TrustedEgressDetector())->isDirectMode());
    }
}
