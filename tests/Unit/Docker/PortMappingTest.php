<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Docker;

use Ngramx\Docker\PortMapping;
use PHPUnit\Framework\TestCase;

class PortMappingTest extends TestCase
{
    public function test_it_splits_on_top_level_colons_only(): void
    {
        $this->assertSame(['4173'], PortMapping::split('4173'));
        $this->assertSame(['8080', '80'], PortMapping::split('8080:80'));
        $this->assertSame(['127.0.0.1', '8080', '80'], PortMapping::split('127.0.0.1:8080:80'));
        $this->assertSame(['${PWA_PORT:-3827}', '4173'], PortMapping::split('${PWA_PORT:-3827}:4173'));
        $this->assertSame(['${PWA_PORT}', '4173'], PortMapping::split('${PWA_PORT}:4173'));
        $this->assertSame(
            ['127.0.0.1', '${PWA_PORT:-3827}', '4173'],
            PortMapping::split('127.0.0.1:${PWA_PORT:-3827}:4173')
        );
    }

    public function test_it_does_not_split_inside_interpolation(): void
    {
        // The naive explode(':') would have produced 3 parts and corrupted the value.
        $this->assertCount(2, PortMapping::split('${EK_PWA_PREVIEW_PORT:-3827}:4173'));
    }

    public function test_host_port_number_for_plain_port(): void
    {
        $this->assertSame(8080, PortMapping::hostPortNumber('8080'));
    }

    public function test_host_port_number_resolves_interpolated_default(): void
    {
        $this->assertSame(3827, PortMapping::hostPortNumber('${PWA_PORT:-3827}'));
        $this->assertSame(3827, PortMapping::hostPortNumber('${PWA_PORT-3827}'));
    }

    public function test_host_port_number_is_null_without_default(): void
    {
        $this->assertNull(PortMapping::hostPortNumber('${PWA_PORT}'));
    }

    public function test_offset_plain_host_port(): void
    {
        $this->assertSame('1080', PortMapping::offsetHostPort('80', 1000));
    }

    public function test_offset_interpolated_default_keeps_variable(): void
    {
        $this->assertSame('${PWA_PORT:-4827}', PortMapping::offsetHostPort('${PWA_PORT:-3827}', 1000));
        $this->assertSame('${PWA_PORT-4827}', PortMapping::offsetHostPort('${PWA_PORT-3827}', 1000));
    }

    public function test_offset_leaves_interpolation_without_default_untouched(): void
    {
        $this->assertSame('${PWA_PORT}', PortMapping::offsetHostPort('${PWA_PORT}', 1000));
    }
}
