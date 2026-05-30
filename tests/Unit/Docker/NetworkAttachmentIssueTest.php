<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Docker;

use Ngramx\Docker\NetworkAttachmentIssue;
use PHPUnit\Framework\TestCase;

class NetworkAttachmentIssueTest extends TestCase
{
    public function test_describe_includes_service_and_short_container_id_and_network_mode(): void
    {
        $issue = new NetworkAttachmentIssue(
            service: 'db',
            containerId: 'abcdef0123456789aaaaaaaaaaaaaaaaaaaa',
            declaredNetworkMode: 'verafind_virginland_network',
        );

        $description = $issue->describe();

        $this->assertStringContainsString('`db`', $description);
        $this->assertStringContainsString('abcdef012345', $description);
        $this->assertStringContainsString('verafind_virginland_network', $description);
        $this->assertStringContainsString('cannot resolve', $description);
    }

    public function test_describe_handles_empty_network_mode(): void
    {
        $issue = new NetworkAttachmentIssue(
            service: 'worker',
            containerId: '0000000000aa',
            declaredNetworkMode: '',
        );

        $this->assertStringContainsString('(unset)', $issue->describe());
    }
}
