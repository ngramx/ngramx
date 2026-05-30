<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Orchestrator;

use Ngramx\Orchestrator\CommandOrchestrator;
use PHPUnit\Framework\TestCase;

class CommandOrchestratorTest extends TestCase
{
    public function test_derive_labels_uses_first_token_basename(): void
    {
        $labels = CommandOrchestrator::deriveLabels([
            'composer validate --strict',
            'vendor/bin/phpstan analyse src',
            'vendor/bin/phpunit',
        ]);

        $this->assertSame(['composer', 'phpstan', 'phpunit'], $labels);
    }

    public function test_derive_labels_disambiguates_duplicates_with_indices(): void
    {
        $labels = CommandOrchestrator::deriveLabels([
            'php artisan queue:work',
            'php artisan schedule:run',
            'php artisan horizon',
        ]);

        $this->assertSame(['php', 'php#2', 'php#3'], $labels);
    }

    public function test_derive_labels_handles_leading_whitespace(): void
    {
        $labels = CommandOrchestrator::deriveLabels([
            '   vendor/bin/phpunit --filter FooTest',
        ]);

        $this->assertSame(['phpunit'], $labels);
    }

    public function test_derive_labels_falls_back_for_empty_commands(): void
    {
        $labels = CommandOrchestrator::deriveLabels(['']);

        $this->assertSame(['cmd'], $labels);
    }
}
