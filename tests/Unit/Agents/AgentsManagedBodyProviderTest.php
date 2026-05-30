<?php

declare(strict_types=1);

namespace Tests\Unit\Agents;

use Cortex\Agents\AgentsManagedBodyProvider;
use PHPUnit\Framework\TestCase;

class AgentsManagedBodyProviderTest extends TestCase
{
    public function test_get_markdown_is_non_empty_and_includes_agents_sections(): void
    {
        $provider = new AgentsManagedBodyProvider();
        $markdown = $provider->getMarkdown();

        $this->assertNotSame('', trim($markdown));
        $this->assertStringContainsString('Development environment', $markdown);
    }

    public function test_get_markdown_excludes_long_form_ticket_workflow(): void
    {
        $provider = new AgentsManagedBodyProvider();
        $markdown = $provider->getMarkdown();

        $this->assertStringNotContainsString('Shared Steps', $markdown);
        $this->assertStringNotContainsString('Shared Step:', $markdown);
        $this->assertStringNotContainsString('# Ticket Types', $markdown);
    }

    public function test_get_markdown_includes_ticket_conventions(): void
    {
        $provider = new AgentsManagedBodyProvider();
        $markdown = $provider->getMarkdown();

        $this->assertStringContainsString('Never open draft PRs', $markdown);
        $this->assertStringContainsString('completion.md', $markdown);
        $this->assertStringContainsString('Click to Test', $markdown);
    }

    public function test_get_markdown_does_not_include_skill_content(): void
    {
        $provider = new AgentsManagedBodyProvider();
        $markdown = $provider->getMarkdown();

        // Linear ticket creation is now a skill
        $this->assertStringNotContainsString('Linear Ticket Conventions', $markdown);
        // Branch naming is now in start-ticket skill
        $this->assertStringNotContainsString('Branch naming', $markdown);
        // PR risk labels are now in create-pr skill
        $this->assertStringNotContainsString('risk:low', $markdown);
    }

    public function test_ticket_folder_path_is_under_dot_cortex(): void
    {
        $provider = new AgentsManagedBodyProvider();
        $markdown = $provider->getMarkdown();

        $this->assertStringContainsString('.cortex/tickets/[ticket-id]/', $markdown);
    }

    /**
     * Regression: previously this used glob(), which silently returns no
     * matches when the templates directory is on a stream wrapper such as
     * phar://. The provider must enumerate the agents directory using
     * scandir() so it works inside a Phar build. We can't easily exercise
     * a real phar:// path here (phar.readonly is on in many environments),
     * but we can prove that file selection works against an arbitrary
     * directory and that only correctly-named files are picked up.
     */
    public function test_get_markdown_enumerates_agents_dir_via_scandir_and_filters_by_pattern(): void
    {
        $tmpRoot = sys_get_temp_dir() . '/cortex_agents_provider_test_' . uniqid();
        $agentsDir = $tmpRoot . '/agents';
        mkdir($agentsDir, 0755, true);

        try {
            file_put_contents($agentsDir . '/01-first.md', "# First\n\nfirst-body");
            file_put_contents($agentsDir . '/02-second.md', "# Second\n\nsecond-body");
            file_put_contents($agentsDir . '/10-tenth.md', "# Tenth\n\ntenth-body");
            file_put_contents($agentsDir . '/README.md', '# Should be ignored');
            file_put_contents($agentsDir . '/1-single-digit.md', '# Should be ignored');
            file_put_contents($agentsDir . '/03-empty.md', '');

            $provider = new AgentsManagedBodyProvider($tmpRoot);
            $markdown = $provider->getMarkdown();

            $this->assertStringContainsString('first-body', $markdown);
            $this->assertStringContainsString('second-body', $markdown);
            $this->assertStringContainsString('tenth-body', $markdown);
            $this->assertStringNotContainsString('Should be ignored', $markdown);

            $this->assertStringContainsString("first-body\n\n---\n\n", $markdown);
            $this->assertStringContainsString("second-body\n\n---\n\n", $markdown);

            $firstPos = strpos($markdown, 'first-body');
            $secondPos = strpos($markdown, 'second-body');
            $tenthPos = strpos($markdown, 'tenth-body');
            $this->assertNotFalse($firstPos);
            $this->assertNotFalse($secondPos);
            $this->assertNotFalse($tenthPos);
            $this->assertLessThan($secondPos, $firstPos);
            $this->assertLessThan($tenthPos, $secondPos);
        } finally {
            @unlink($agentsDir . '/01-first.md');
            @unlink($agentsDir . '/02-second.md');
            @unlink($agentsDir . '/10-tenth.md');
            @unlink($agentsDir . '/README.md');
            @unlink($agentsDir . '/1-single-digit.md');
            @unlink($agentsDir . '/03-empty.md');
            @rmdir($agentsDir);
            @rmdir($tmpRoot);
        }
    }

    public function test_get_markdown_returns_empty_string_when_agents_dir_missing(): void
    {
        $tmpRoot = sys_get_temp_dir() . '/cortex_agents_missing_' . uniqid();
        mkdir($tmpRoot, 0755, true);

        try {
            $provider = new AgentsManagedBodyProvider($tmpRoot);
            $this->assertSame('', $provider->getMarkdown());
        } finally {
            @rmdir($tmpRoot);
        }
    }
}
