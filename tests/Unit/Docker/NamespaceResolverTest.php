<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Docker;

use Ngramx\Docker\NamespaceResolver;
use PHPUnit\Framework\TestCase;

class NamespaceResolverTest extends TestCase
{
    private NamespaceResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new NamespaceResolver();
    }

    public function test_it_derives_namespace_from_directory(): void
    {
        $namespace = $this->resolver->deriveFromDirectory('/workspace/agent-1/project');

        $this->assertEquals('ngramx-agent-1-project', $namespace);
    }

    public function test_it_takes_last_two_segments(): void
    {
        $namespace = $this->resolver->deriveFromDirectory('/very/long/path/to/user/myapp');

        $this->assertEquals('ngramx-user-myapp', $namespace);
    }

    public function test_it_sanitizes_special_characters(): void
    {
        $namespace = $this->resolver->deriveFromDirectory('/workspace/agent_1/my.project');

        $this->assertEquals('ngramx-agent-1-my-project', $namespace);
    }

    public function test_it_handles_trailing_slash(): void
    {
        $namespace = $this->resolver->deriveFromDirectory('/workspace/agent-1/project/');

        $this->assertEquals('ngramx-agent-1-project', $namespace);
    }

    public function test_it_converts_to_lowercase(): void
    {
        $namespace = $this->resolver->deriveFromDirectory('/workspace/Agent-1/MyProject');

        $this->assertEquals('ngramx-agent-1-myproject', $namespace);
    }

    public function test_it_validates_valid_namespace(): void
    {
        $this->resolver->validate('valid-namespace-123');
        $this->resolver->validate('a');
        $this->resolver->validate('namespace');

        // Test passes if no exception is thrown
        $this->addToAssertionCount(1);
    }

    public function test_it_rejects_namespace_with_uppercase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Must contain only lowercase letters');

        $this->resolver->validate('Invalid-Namespace');
    }

    public function test_it_rejects_namespace_with_special_characters(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->resolver->validate('invalid_namespace');
    }

    public function test_it_rejects_namespace_starting_with_hyphen(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->resolver->validate('-invalid');
    }

    public function test_it_rejects_namespace_ending_with_hyphen(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->resolver->validate('invalid-');
    }

    public function test_it_rejects_namespace_too_long(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('too long');

        $longNamespace = str_repeat('a', 64);
        $this->resolver->validate($longNamespace);
    }

    public function test_it_accepts_namespace_with_63_characters(): void
    {
        $namespace = str_repeat('a', 63);
        $this->resolver->validate($namespace);

        // Test passes if no exception is thrown
        $this->addToAssertionCount(1);
    }
}
