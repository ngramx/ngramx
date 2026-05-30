<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Config;

use Ngramx\Config\ConfigWarningChecker;
use Ngramx\Config\Schema\CommandDefinition;
use Ngramx\Config\Schema\DockerConfig;
use Ngramx\Config\Schema\N8nConfig;
use Ngramx\Config\Schema\NgramxConfig;
use Ngramx\Config\Schema\SetupConfig;
use PHPUnit\Framework\TestCase;

class ConfigWarningCheckerTest extends TestCase
{
    private ConfigWarningChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new ConfigWarningChecker();
    }

    public function test_no_warnings_when_all_recommended_commands_defined(): void
    {
        $config = $this->createConfig([
            'clear' => new CommandDefinition(
                command: 'composer install && php artisan migrate',
                description: 'Sync environment',
            ),
            'fresh' => new CommandDefinition(
                command: 'php artisan migrate:fresh --seed',
                description: 'Reset database',
            ),
        ]);

        $warnings = $this->checker->check($config);

        $this->assertSame([], $warnings);
    }

    public function test_warns_when_recommended_commands_are_missing(): void
    {
        $config = $this->createConfig([]);

        $warnings = $this->checker->check($config);

        $this->assertCount(2, $warnings);
        $this->assertStringContainsString("'clear'", $warnings[0]);
        $this->assertStringContainsString('not defined', $warnings[0]);
        $this->assertStringContainsString("'fresh'", $warnings[1]);
        $this->assertStringContainsString('not defined', $warnings[1]);
    }

    public function test_warns_when_command_has_empty_string(): void
    {
        $config = $this->createConfig([
            'clear' => new CommandDefinition(
                command: '',
                description: 'Sync environment',
            ),
            'fresh' => new CommandDefinition(
                command: 'php artisan migrate:fresh --seed',
                description: 'Reset database',
            ),
        ]);

        $warnings = $this->checker->check($config);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString("'clear'", $warnings[0]);
        $this->assertStringContainsString('empty command string', $warnings[0]);
    }

    public function test_warns_when_command_has_whitespace_only_string(): void
    {
        $config = $this->createConfig([
            'clear' => new CommandDefinition(
                command: '   ',
                description: 'Sync environment',
            ),
            'fresh' => new CommandDefinition(
                command: '',
                description: 'Reset database',
            ),
        ]);

        $warnings = $this->checker->check($config);

        $this->assertCount(2, $warnings);
        $this->assertStringContainsString("'clear'", $warnings[0]);
        $this->assertStringContainsString('empty command string', $warnings[0]);
        $this->assertStringContainsString("'fresh'", $warnings[1]);
        $this->assertStringContainsString('empty command string', $warnings[1]);
    }

    public function test_only_warns_about_missing_commands_not_extra_ones(): void
    {
        $config = $this->createConfig([
            'clear' => new CommandDefinition(
                command: 'composer install',
                description: 'Sync environment',
            ),
            'fresh' => new CommandDefinition(
                command: 'php artisan migrate:fresh --seed',
                description: 'Reset database',
            ),
            'test' => new CommandDefinition(
                command: 'php artisan test',
                description: 'Run tests',
            ),
        ]);

        $warnings = $this->checker->check($config);

        $this->assertSame([], $warnings);
    }

    public function test_partial_definition_warns_only_for_missing(): void
    {
        $config = $this->createConfig([
            'fresh' => new CommandDefinition(
                command: 'php artisan migrate:fresh --seed',
                description: 'Reset database',
            ),
        ]);

        $warnings = $this->checker->check($config);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString("'clear'", $warnings[0]);
    }

    /**
     * @param array<string, CommandDefinition> $commands
     */
    private function createConfig(array $commands): NgramxConfig
    {
        return new NgramxConfig(
            version: '1.0',
            docker: new DockerConfig(
                composeFile: 'docker-compose.yml',
                primaryService: 'app',
                appUrl: 'http://localhost:80',
                waitFor: [],
            ),
            setup: new SetupConfig(preStart: [], initialize: []),
            n8n: new N8nConfig(workflowsDir: './.n8n'),
            commands: $commands,
        );
    }
}
