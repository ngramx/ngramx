<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Host;

use Ngramx\Host\EtcHostsHint;
use PHPUnit\Framework\TestCase;

final class EtcHostsHintTest extends TestCase
{
    public function test_it_returns_null_for_localhost(): void
    {
        $this->assertNull(EtcHostsHint::suggestedHostsLine('http://localhost:8080/app'));
    }

    public function test_it_returns_null_for_127_loopback(): void
    {
        $this->assertNull(EtcHostsHint::suggestedHostsLine('http://127.0.0.1/'));
    }

    public function test_it_returns_null_for_dot_localhost(): void
    {
        $this->assertNull(EtcHostsHint::suggestedHostsLine('https://myapp.localhost'));
    }

    public function test_it_returns_null_for_invalid_url_without_host(): void
    {
        $this->assertNull(EtcHostsHint::suggestedHostsLine('not-a-url'));
    }

    public function test_it_returns_null_for_lan_ipv4_in_app_url(): void
    {
        $this->assertNull(EtcHostsHint::suggestedHostsLine('http://192.168.1.100:8080/'));
    }

    public function test_it_returns_null_for_zero_ipv4_in_app_url(): void
    {
        $this->assertNull(EtcHostsHint::suggestedHostsLine('http://0.0.0.0:8080'));
    }

    public function test_it_suggests_hosts_line_when_hostname_does_not_resolve(): void
    {
        $host = 'example.invalid';
        $resolved = @gethostbyname($host);
        if ($resolved !== $host) {
            $this->markTestSkipped('example.invalid resolved in this environment; cannot assert unresolvable behaviour');
        }

        $this->assertSame(
            '127.0.0.1 '.$host,
            EtcHostsHint::suggestedHostsLine('http://'.$host.'/path')
        );
    }
}
