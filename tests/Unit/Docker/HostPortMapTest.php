<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Docker;

use Ngramx\Docker\HostPortMap;
use PHPUnit\Framework\TestCase;

class HostPortMapTest extends TestCase
{
    public function test_it_adds_a_first_remap(): void
    {
        $this->assertSame(
            [64506 => 14506],
            HostPortMap::repointFailed([], 64506, 14506),
        );
    }

    public function test_it_repoints_the_original_when_the_replacement_fails(): void
    {
        $this->assertSame(
            [64506 => 14606],
            HostPortMap::repointFailed([64506 => 14506], 14506, 14606),
        );
    }

    public function test_it_updates_an_existing_key_when_that_port_fails_again(): void
    {
        $this->assertSame(
            [64506 => 14606],
            HostPortMap::repointFailed([64506 => 14506], 64506, 14606),
        );
    }
}
