<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Postmaclone\EngineDetector;
use Ngramx\Postmaclone\Exception\PostmacloneException;
use PHPUnit\Framework\TestCase;

class EngineDetectorTest extends TestCase
{
    private EngineDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new EngineDetector();
    }

    public function test_detects_postgres_from_compose(): void
    {
        $path = dirname(__DIR__, 2) . '/fixtures/postmaclone/compose-postgres.yml';
        $this->assertSame('postgres', $this->detector->detectFromCompose($path));
    }

    public function test_detects_mysql_from_compose(): void
    {
        $path = dirname(__DIR__, 2) . '/fixtures/postmaclone/compose-mysql.yml';
        $this->assertSame('mysql', $this->detector->detectFromCompose($path));
    }

    public function test_configured_engine_wins(): void
    {
        $path = dirname(__DIR__, 2) . '/fixtures/postmaclone/compose-mysql.yml';
        $this->assertSame('postgres', $this->detector->detect('postgres', $path));
    }

    public function test_mismatch_warning(): void
    {
        $path = dirname(__DIR__, 2) . '/fixtures/postmaclone/compose-mysql.yml';
        $warning = $this->detector->detectionMismatch('postgres', $path);
        $this->assertNotNull($warning);
        $this->assertStringContainsString('postgres', $warning);
        $this->assertStringContainsString('mysql', $warning);
    }

    public function test_throws_when_undetectable(): void
    {
        $this->expectException(PostmacloneException::class);
        $this->detector->detect(null, '/nonexistent/compose.yml');
    }
}
