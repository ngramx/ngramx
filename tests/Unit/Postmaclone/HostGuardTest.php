<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Postmaclone\Exception\PostmacloneException;
use Ngramx\Postmaclone\HostGuard;
use PHPUnit\Framework\TestCase;

class HostGuardTest extends TestCase
{
    public function test_allows_when_deny_list_empty(): void
    {
        $guard = new HostGuard();
        $guard->assertAllowed('prod.example.com', []);
        $this->assertTrue(true);
    }

    public function test_blocks_matching_host(): void
    {
        $guard = new HostGuard();
        $this->expectException(PostmacloneException::class);
        $guard->assertAllowed('postgresql://u:p@db.prod.example.com:5432/app', ['*.prod.example.com']);
    }

    public function test_allows_non_matching_host(): void
    {
        $guard = new HostGuard();
        $guard->assertAllowed('postgresql://u:p@localhost:5432/app', ['*.prod.example.com']);
        $this->assertTrue(true);
    }
}
