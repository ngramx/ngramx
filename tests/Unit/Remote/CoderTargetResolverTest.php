<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Remote;

use Ngramx\Remote\CoderTarget;
use Ngramx\Remote\CoderTargetResolver;
use PHPUnit\Framework\TestCase;

class CoderTargetResolverTest extends TestCase
{
    public function testFallsBackToDefaults(): void
    {
        $target = (new CoderTargetResolver())->resolve();

        $this->assertSame(CoderTarget::DEFAULT_HOST, $target->host);
        $this->assertSame(CoderTarget::DEFAULT_CONTAINER, $target->container);
    }

    public function testEnvironmentOverridesDefaults(): void
    {
        $resolver = new CoderTargetResolver([
            CoderTargetResolver::ENV_HOST => 'staging.test',
            CoderTargetResolver::ENV_CONTAINER => 'agent-2',
            CoderTargetResolver::ENV_PORT => '2222',
        ]);

        $target = $resolver->resolve();

        $this->assertSame('staging.test', $target->host);
        $this->assertSame('agent-2', $target->container);
        $this->assertSame(2222, $target->port);
        $this->assertSame(CoderTarget::DEFAULT_SSH_USER, $target->sshUser);
    }

    public function testOptionsOverrideEnvironment(): void
    {
        $resolver = new CoderTargetResolver([
            CoderTargetResolver::ENV_HOST => 'staging.test',
        ]);

        $target = $resolver->resolve(['host' => 'prod.test', 'container-user' => 'root']);

        $this->assertSame('prod.test', $target->host);
        $this->assertSame('root', $target->containerUser);
    }

    public function testEmptyOptionsAreIgnored(): void
    {
        $resolver = new CoderTargetResolver([CoderTargetResolver::ENV_HOST => 'staging.test']);

        $target = $resolver->resolve(['host' => null, 'port' => null]);

        $this->assertSame('staging.test', $target->host);
        $this->assertNull($target->port);
    }
}
