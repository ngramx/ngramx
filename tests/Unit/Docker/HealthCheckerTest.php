<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Docker;

use Ngramx\Docker\HealthChecker;
use PHPUnit\Framework\TestCase;

class HealthCheckerTest extends TestCase
{
    private HealthChecker $healthChecker;

    protected function setUp(): void
    {
        $this->healthChecker = new HealthChecker();
    }

    public function test_it_can_be_instantiated(): void
    {
        $this->assertInstanceOf(HealthChecker::class, $this->healthChecker);
    }

    public function test_get_health_status_returns_unknown_for_nonexistent_service(): void
    {
        $status = $this->healthChecker->getHealthStatus(
            '/nonexistent/docker-compose.yml',
            'nonexistent-service'
        );

        $this->assertEquals('unknown', $status);
    }
}
