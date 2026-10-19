<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Docker;

use Ngramx\Docker\WindowsExcludedPortRanges;
use PHPUnit\Framework\TestCase;

class WindowsExcludedPortRangesTest extends TestCase
{
    public function test_it_parses_netsh_excluded_ranges(): void
    {
        $output = <<<'TXT'
Protocol tcp Port Exclusion Ranges

Start Port    End Port
----------    --------
      2869        2869
     64480       64579
     50000       50059     *

* - Administered port exclusions.
TXT;

        $ranges = new WindowsExcludedPortRanges(static fn (): string => $output);

        $this->assertTrue($ranges->contains(64506));
        $this->assertTrue($ranges->contains(64480));
        $this->assertTrue($ranges->contains(64579));
        $this->assertTrue($ranges->contains(50000));
        $this->assertFalse($ranges->contains(64580));
        $this->assertFalse($ranges->contains(56306));
        $this->assertFalse($ranges->contains(80));
    }

    public function test_it_treats_empty_output_as_no_exclusions(): void
    {
        $ranges = new WindowsExcludedPortRanges(static fn (): string => '');

        $this->assertFalse($ranges->contains(64506));
    }

    public function test_parse_returns_integer_pairs(): void
    {
        $this->assertSame(
            [[64480, 64579], [50000, 50059]],
            WindowsExcludedPortRanges::parse("     64480       64579\n     50000       50059     *\n"),
        );
    }
}
