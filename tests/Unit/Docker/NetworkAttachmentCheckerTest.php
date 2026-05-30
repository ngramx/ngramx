<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Docker;

use Ngramx\Docker\DockerCompose;
use Ngramx\Docker\NetworkAttachmentChecker;
use PHPUnit\Framework\TestCase;

class NetworkAttachmentCheckerTest extends TestCase
{
    public function test_it_can_be_instantiated_with_default_compose(): void
    {
        $checker = new NetworkAttachmentChecker();

        $this->assertInstanceOf(NetworkAttachmentChecker::class, $checker);
    }

    public function test_check_all_returns_empty_when_compose_has_no_services(): void
    {
        $compose = $this->createMock(DockerCompose::class);
        $compose->method('listServices')->willReturn([]);

        $checker = new NetworkAttachmentChecker($compose);

        $this->assertSame([], $checker->checkAll('/tmp/does-not-exist.yml'));
    }

    public function test_check_all_returns_empty_when_compose_path_does_not_exist(): void
    {
        $checker = new NetworkAttachmentChecker();

        $this->assertSame([], $checker->checkAll('/tmp/definitely-not-here-' . uniqid() . '.yml'));
    }
}
