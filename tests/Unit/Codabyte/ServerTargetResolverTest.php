<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Codabyte;

use Ngramx\Codabyte\ServerTarget;
use Ngramx\Codabyte\ServerTargetResolver;
use PHPUnit\Framework\TestCase;

class ServerTargetResolverTest extends TestCase
{
    public function testFallsBackToDefaults(): void
    {
        $target = (new ServerTargetResolver())->resolve();

        $this->assertSame(ServerTarget::DEFAULT_HOST, $target->host);
        $this->assertSame(ServerTarget::DEFAULT_CONTAINER, $target->container);
    }

    public function testEnvironmentOverridesDefaults(): void
    {
        $resolver = new ServerTargetResolver([
            ServerTargetResolver::ENV_HOST => 'staging.test',
            ServerTargetResolver::ENV_CONTAINER => 'agent-2',
            ServerTargetResolver::ENV_PORT => '2222',
        ]);

        $target = $resolver->resolve();

        $this->assertSame('staging.test', $target->host);
        $this->assertSame('agent-2', $target->container);
        $this->assertSame(2222, $target->port);
        $this->assertSame(ServerTarget::DEFAULT_SSH_USER, $target->sshUser);
    }

    public function testOptionsOverrideEnvironment(): void
    {
        $resolver = new ServerTargetResolver([
            ServerTargetResolver::ENV_HOST => 'staging.test',
        ]);

        $target = $resolver->resolve(['host' => 'prod.test', 'container-user' => 'root']);

        $this->assertSame('prod.test', $target->host);
        $this->assertSame('root', $target->containerUser);
    }

    public function testEmptyOptionsAreIgnored(): void
    {
        $resolver = new ServerTargetResolver([ServerTargetResolver::ENV_HOST => 'staging.test']);

        $target = $resolver->resolve(['host' => null, 'port' => null]);

        $this->assertSame('staging.test', $target->host);
        $this->assertNull($target->port);
    }
}
