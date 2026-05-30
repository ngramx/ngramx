<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Cortex\Config\Exception\ConfigException;
use Cortex\Config\Validator\ConfigValidator;
use PHPUnit\Framework\TestCase;

class AgentsConfigValidationTest extends TestCase
{
    private ConfigValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ConfigValidator();
    }

    /**
     * @param array<string, mixed> $agents
     * @return array<string, mixed>
     */
    private function minimalConfig(array $agents = []): array
    {
        $config = [
            'version' => '1.0',
            'docker' => [
                'compose_file' => 'docker-compose.yml',
                'primary_service' => 'app',
                'app_url' => 'http://localhost',
            ],
        ];

        if ($agents !== []) {
            $config['agents'] = $agents;
        }

        return $config;
    }

    public function test_valid_agents_config_passes(): void
    {
        $config = $this->minimalConfig([
            'targets' => ['agents_md', 'cursor_rules', 'claude_md', 'copilot_instructions'],
            'skills' => ['cursor', 'claude'],
        ]);

        $this->validator->validate($config);
        $this->assertTrue(true);
    }

    public function test_empty_agents_section_passes(): void
    {
        $config = $this->minimalConfig([]);
        $this->validator->validate($config);
        $this->assertTrue(true);
    }

    public function test_invalid_target_throws(): void
    {
        $config = $this->minimalConfig([
            'targets' => ['agents_md', 'invalid_target'],
        ]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage("unknown target 'invalid_target'");
        $this->validator->validate($config);
    }

    public function test_invalid_skill_target_throws(): void
    {
        $config = $this->minimalConfig([
            'skills' => ['cursor', 'invalid_skill'],
        ]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage("unknown skill target 'invalid_skill'");
        $this->validator->validate($config);
    }

    public function test_non_string_target_throws(): void
    {
        $config = $this->minimalConfig([
            'targets' => ['agents_md', 123],
        ]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('must be a non-empty string');
        $this->validator->validate($config);
    }

    public function test_non_array_targets_throws(): void
    {
        $config = $this->minimalConfig([
            'targets' => 'agents_md',
        ]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('agents.targets must be an array');
        $this->validator->validate($config);
    }

    public function test_config_without_agents_section_passes(): void
    {
        $config = $this->minimalConfig();
        $this->validator->validate($config);
        $this->assertTrue(true);
    }
}
