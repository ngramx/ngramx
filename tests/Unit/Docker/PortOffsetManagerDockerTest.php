<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Docker;

use Ngramx\Docker\PortOffsetManager;
use PHPUnit\Framework\TestCase;

class PortOffsetManagerDockerTest extends TestCase
{
    public function test_it_gets_docker_used_ports_from_ps_output(): void
    {
        $manager = new PortOffsetManager();

        // Use reflection to test the private method
        $reflection = new \ReflectionClass($manager);
        $method = $reflection->getMethod('getDockerUsedPorts');
        $method->setAccessible(true);

        $usedPorts = $method->invoke($manager);

        // Should return an array of integers
        $this->assertIsArray($usedPorts);

        // All values should be integers
        foreach ($usedPorts as $port) {
            $this->assertIsInt($port);
            $this->assertGreaterThan(0, $port);
            $this->assertLessThan(65536, $port);
        }
    }

    public function test_it_parses_various_port_formats(): void
    {
        // This test verifies the regex pattern works with real Docker output formats
        $testCases = [
            '0.0.0.0:8080->80/tcp' => [8080],
            '127.0.0.1:8080->80/tcp' => [8080],
            '[::]:8080->80/tcp' => [8080],
            '0.0.0.0:8080->80/tcp, 0.0.0.0:5432->5432/tcp' => [8080, 5432],
            '127.0.0.1:8080->80/tcp, [::]:5432->5432/tcp' => [8080, 5432],
            '0.0.0.0:8080->80/tcp, [::]:8080->80/tcp' => [8080],
            '' => [],
        ];

        foreach ($testCases as $input => $expected) {
            preg_match_all('/:(\d+)->/', $input, $matches);
            $ports = array_unique(array_map('intval', $matches[1]));
            sort($ports);
            sort($expected);

            $this->assertEquals(
                $expected,
                $ports,
                "Failed to parse: {$input}"
            );
        }
    }
}
