<?php

declare(strict_types=1);

namespace Tests\Unit\Agents;

use Ngramx\Agents\AgentsManagedBodyProvider;
use PHPUnit\Framework\TestCase;

class AgentsManagedBodyProviderTest extends TestCase
{
    public function test_get_markdown_is_a_short_index_of_skills(): void
    {
        $provider = new AgentsManagedBodyProvider();
        $markdown = $provider->getMarkdown();

        $this->assertNotSame('', trim($markdown));
        $this->assertStringContainsString('ngramx-development-environment', $markdown);
        $this->assertStringContainsString('ngramx-start-ticket', $markdown);
        $this->assertStringContainsString('Bring the local stack up with ngramx', $markdown);
        $this->assertStringNotContainsString('HasUuids', $markdown);
        $this->assertStringNotContainsString('```json', $markdown);
        $this->assertStringNotContainsString('Never open draft PRs', $markdown);
    }

    public function test_get_markdown_does_not_inline_skill_bodies(): void
    {
        $provider = new AgentsManagedBodyProvider();
        $markdown = $provider->getMarkdown();

        $this->assertStringNotContainsString('risk:low', $markdown);
        $this->assertStringNotContainsString('Shared Steps', $markdown);
        $this->assertStringNotContainsString('# Ticket Types', $markdown);
    }

    public function test_get_markdown_enumerates_skill_dirs_and_ignores_other_files(): void
    {
        $tmpRoot = sys_get_temp_dir() . '/ngramx_agents_provider_test_' . uniqid();
        $skillsDir = $tmpRoot . '/skills';
        mkdir($skillsDir . '/zeta-skill', 0755, true);
        mkdir($skillsDir . '/alpha-skill', 0755, true);
        mkdir($skillsDir . '/not-a-skill', 0755, true);

        try {
            file_put_contents($skillsDir . '/zeta-skill/SKILL.md', "---\nname: zeta-skill\ndescription: Zeta summary.\n---\n\n# Zeta body that must stay out of the index\n");
            file_put_contents($skillsDir . '/alpha-skill/SKILL.md', "---\nname: alpha-skill\ndescription: Alpha summary.\n---\n\n# Alpha\n");
            file_put_contents($skillsDir . '/not-a-skill/README.md', 'Should be ignored');
            file_put_contents($skillsDir . '/notes.md', 'Should be ignored');

            $provider = new AgentsManagedBodyProvider($tmpRoot);
            $markdown = $provider->getMarkdown();

            $this->assertStringContainsString('ngramx-alpha-skill', $markdown);
            $this->assertStringContainsString('Alpha summary.', $markdown);
            $this->assertStringContainsString('ngramx-zeta-skill', $markdown);
            $this->assertStringContainsString('Zeta summary.', $markdown);
            $this->assertStringNotContainsString('must stay out of the index', $markdown);
            $this->assertStringNotContainsString('Should be ignored', $markdown);

            $alphaPos = strpos($markdown, 'ngramx-alpha-skill');
            $zetaPos = strpos($markdown, 'ngramx-zeta-skill');
            $this->assertNotFalse($alphaPos);
            $this->assertNotFalse($zetaPos);
            $this->assertLessThan($zetaPos, $alphaPos);
        } finally {
            $this->removeDirectory($tmpRoot);
        }
    }

    public function test_get_markdown_returns_empty_string_when_skills_dir_missing(): void
    {
        $tmpRoot = sys_get_temp_dir() . '/ngramx_agents_missing_' . uniqid();
        mkdir($tmpRoot, 0755, true);

        try {
            $provider = new AgentsManagedBodyProvider($tmpRoot);
            $this->assertSame('', $provider->getMarkdown());
        } finally {
            @rmdir($tmpRoot);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
